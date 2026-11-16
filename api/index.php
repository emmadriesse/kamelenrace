<?php
// Kamelenrace: kleine API voor lunch- en vergaderwandelingen.
// Geen login (op goed vertrouwen). Alles staat in één db.json in de datamap,
// en elke schrijfactie krijgt een exclusieve lock zodat er niets verloren gaat.
// Alle verzoeken naar /api/... komen hier binnen (via .htaccess of de dev-router).

declare(strict_types=1);
mb_internal_encoding('UTF-8');

const MAX_CAMELS = 200;
const MAX_ROUTES = 100;
const MAX_WALKS = 20000;
const MAX_KM = 100;

class HttpError extends Exception
{
    public $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function fail(int $status, string $message): void
{
    throw new HttpError($status, $message);
}

function send_json($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function body(): array
{
    $b = json_decode(file_get_contents('php://input') ?: '[]', true);
    return is_array($b) ? $b : [];
}

function clean($s, int $max): string
{
    if (!is_string($s) && !is_numeric($s)) return '';
    return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s)), 0, $max);
}

function new_id(): string
{
    do {
        $id = bin2hex(random_bytes(6));
    } while (ctype_digit($id)); // nooit puur cijfers (PHP maakt daar anders int-keys van)
    return $id;
}

// ---------- opslag ----------

function data_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $candidates = [];
    if (getenv('KAMEEL_DATA_DIR')) $candidates[] = getenv('KAMEEL_DATA_DIR');
    // Op de server: naast public_html, dus nooit via de website op te vragen en
    // nooit overschreven door een deploy
    if (basename(dirname(__DIR__)) === 'public_html') $candidates[] = dirname(__DIR__, 2) . '/kamelenrace-data';
    // Noodoplossing: binnen /api (afgeschermd doordat alles naar index.php gaat)
    $candidates[] = __DIR__ . '/data';
    foreach ($candidates as $c) {
        if ((is_dir($c) || @mkdir($c, 0700, true)) && is_writable($c)) return $dir = rtrim($c, '/');
    }
    throw new HttpError(500, 'Geen schrijfbare datamap gevonden');
}

function defaults(): array
{
    return [
        'camels' => [],  // { id, name, color, created }
        'routes' => [],  // { id, name, km, created }
        'walks' => [],   // { id, walkers: [camelId], km, kind, date, routeId, routeName, created }
        'config' => ['teamGoal' => 500, 'personalGoal' => 100],
    ];
}

$DB = null;
$LOCK = null;

function &db(bool $write = false): array
{
    global $DB, $LOCK;
    if ($DB !== null) return $DB;
    $dir = data_dir();
    $LOCK = fopen($dir . '/db.lock', 'c');
    flock($LOCK, $write ? LOCK_EX : LOCK_SH);
    $file = $dir . '/db.json';
    $def = defaults();
    $disk = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $DB = array_merge($def, $disk);
    $DB['config'] = array_merge($def['config'], $disk['config'] ?? []);
    return $DB;
}

function save(): void
{
    global $DB;
    $file = data_dir() . '/db.json';
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($DB, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $file);
}

function release_lock(): void
{
    global $LOCK;
    if ($LOCK) {
        flock($LOCK, LOCK_UN);
        fclose($LOCK);
        $LOCK = null;
    }
}
register_shutdown_function('release_lock');

function state(): array
{
    $d = db();
    return [
        'camels' => array_values($d['camels']),
        'routes' => array_values($d['routes']),
        'walks' => array_values($d['walks']),
        'config' => $d['config'],
    ];
}

function remove_by_id(string $key, string $id): void
{
    global $DB;
    $before = count($DB[$key]);
    $DB[$key] = array_values(array_filter($DB[$key], function ($x) use ($id) { return ($x['id'] ?? '') !== $id; }));
    if (count($DB[$key]) === $before) fail(404, 'Niet gevonden. Misschien heeft iemand anders het al verwijderd.');
}

function km_value($v): float
{
    $km = round((float) str_replace(',', '.', (string) $v), 1);
    if (!($km > 0) || $km > MAX_KM) fail(400, 'Vul een afstand tussen 0,1 en ' . MAX_KM . ' km in.');
    return $km;
}

// ---------- routes ----------

$routes = [];
function route(string $method, string $pattern, callable $fn): void
{
    global $routes;
    $routes[] = [$method, '#^' . $pattern . '$#', $fn];
}

route('GET', '/api/state', function () {
    return state();
});

route('POST', '/api/camels', function () {
    global $DB;
    $b = body();
    db(true);
    $name = clean($b['name'] ?? '', 40);
    $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($b['color'] ?? '')) ? $b['color'] : '#D1495B';
    if ($name === '') fail(400, 'Vul een naam in.');
    foreach ($DB['camels'] as $c) {
        if (mb_strtolower($c['name']) === mb_strtolower($name)) fail(409, "$name heeft al een kameel.");
    }
    if (count($DB['camels']) >= MAX_CAMELS) fail(400, 'Er staan al genoeg kamelen aan de start.');
    $DB['camels'][] = ['id' => new_id(), 'name' => $name, 'color' => $color, 'created' => time()];
    save();
    return state();
});

route('DELETE', '/api/camels/([a-z0-9]+)', function ($id) {
    global $DB;
    db(true);
    remove_by_id('camels', $id);
    // Haal de kameel ook uit wandelingen; een wandeling zonder lopers verdwijnt
    foreach ($DB['walks'] as &$w) $w['walkers'] = array_values(array_diff($w['walkers'] ?? [], [$id]));
    unset($w);
    $DB['walks'] = array_values(array_filter($DB['walks'], function ($w) { return count($w['walkers']) > 0; }));
    save();
    return state();
});

route('POST', '/api/routes', function () {
    global $DB;
    $b = body();
    db(true);
    $name = clean($b['name'] ?? '', 50);
    if ($name === '') fail(400, 'Vul een naam voor de route in.');
    $km = km_value($b['km'] ?? 0);
    if (count($DB['routes']) >= MAX_ROUTES) fail(400, 'Er zijn al heel veel routes. Verwijder er eerst een.');
    $DB['routes'][] = ['id' => new_id(), 'name' => $name, 'km' => $km, 'created' => time()];
    save();
    return state();
});

route('DELETE', '/api/routes/([a-z0-9]+)', function ($id) {
    db(true);
    remove_by_id('routes', $id);
    save(); // wandelingen houden hun routenaam en afstand
    return state();
});

route('POST', '/api/walks', function () {
    global $DB;
    $b = body();
    db(true);
    $known = array_column($DB['camels'], 'id');
    $walkers = array_values(array_unique(array_filter((array) ($b['walkers'] ?? []), function ($id) use ($known) {
        return is_string($id) && in_array($id, $known, true);
    })));
    if (!$walkers) fail(400, 'Kies wie er meeliepen.');
    $km = km_value($b['km'] ?? 0);
    $kind = ($b['kind'] ?? '') === 'vergader' ? 'vergader' : 'lunch';
    $date = (string) ($b['date'] ?? '');
    $today = (new DateTime('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > $today) $date = $today;
    $routeName = 'Eigen route';
    $routeId = null;
    foreach ($DB['routes'] as $r) {
        if ($r['id'] === ($b['routeId'] ?? null)) {
            $routeId = $r['id'];
            $routeName = $r['name'];
        }
    }
    if (count($DB['walks']) >= MAX_WALKS) fail(400, 'De opslag is vol. Verwijder oude wandelingen.');
    $DB['walks'][] = ['id' => new_id(), 'walkers' => $walkers, 'km' => $km, 'kind' => $kind, 'date' => $date,
        'routeId' => $routeId, 'routeName' => $routeName, 'created' => (int) round(microtime(true) * 1000)];
    save();
    return state();
});

route('DELETE', '/api/walks/([a-z0-9]+)', function ($id) {
    db(true);
    remove_by_id('walks', $id);
    save();
    return state();
});

route('PUT', '/api/config', function () {
    global $DB;
    $b = body();
    db(true);
    $team = (int) round((float) ($b['teamGoal'] ?? 0));
    $personal = (int) round((float) ($b['personalGoal'] ?? 0));
    if ($team < 1 || $personal < 1 || $team > 1000000 || $personal > 100000) fail(400, 'Vul twee positieve getallen in.');
    $DB['config'] = ['teamGoal' => $team, 'personalGoal' => $personal];
    save();
    return state();
});

// ---------- afhandeling ----------

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/');
// Werkt ook als de site in een submap staat (bijv. /kamelenrace/api/state)
$pos = strpos($path, '/api/');
if ($pos !== false) $path = substr($path, $pos);

try {
    foreach ($routes as [$m, $re, $fn]) {
        if ($m === $method && preg_match($re, $path, $params)) {
            array_shift($params);
            send_json($fn(...$params));
            exit;
        }
    }
    fail(404, 'Niet gevonden');
} catch (HttpError $e) {
    send_json(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log((string) $e);
    send_json(['error' => 'Er ging iets mis op de server'], 500);
}
