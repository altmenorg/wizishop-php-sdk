<?php
/**
 * Harnais du coupe-circuit et des délais (v2.2.0). Réponses simulées, plus deux
 * essais réseau locaux pour les délais : une adresse qui ne répond pas
 * (connexion) et un serveur local qui accepte sans jamais répondre (requête).
 * Le coupe-circuit écrit dans un répertoire à lui, jamais dans celui de la
 * production. Code de sortie : nombre d'échecs.
 *
 *   php breaker.php <sdk/src> <projet/vendor/autoload.php> [--sans-reseau]
 */

require __DIR__ . '/bootstrap.php';
sdk_bootstrap($argv[1], $argv[2]);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use WiziShop\SDK\ApiClientFactory;
use WiziShop\SDK\AuthenticatedApiClient;
use WiziShop\SDK\CircuitBreaker;
use WiziShop\SDK\Exception\ApiUnavailableException;
use WiziShop\SDK\Model\JWT;

$root = sys_get_temp_dir() . '/sdk-breaker-test-' . getmypid();
@mkdir($root, 0700, true);
register_shutdown_function(function () use ($root) {
    array_map('unlink', glob($root . '/*') ?: []);
    @rmdir($root);
});

function b64url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
$token = b64url('{"alg":"HS256","typ":"JWT"}') . '.' . b64url(json_encode(['id_shop' => 999, 'exp' => time() + 3600])) . '.sig';

$failures = 0;
function ok($name, $passed, $detail = '')
{
    global $failures;
    $failures += $passed ? 0 : 1;
    printf("%-6s %s%s\n", $passed ? 'OK' : 'ÉCHEC', $name, $detail !== '' ? " — $detail" : '');
}

/** Un client sur réponses simulées, avec le coupe-circuit de test ; $history compte ce qui part vraiment. */
function client(array $queue, &$history, $breaker = true)
{
    global $token, $root;
    $history = [];
    $stack = HandlerStack::create(new MockHandler($queue));
    $config = ['shopid' => 1, 'handler' => $stack];
    if ($breaker) {
        $config['circuit_breaker'] = new CircuitBreaker($root);
    }
    $c = new AuthenticatedApiClient(JWT::fromString($token), $config);
    $stack->push(Middleware::history($history));

    return $c;
}

function attempt(callable $f)
{
    try {
        return $f();
    } catch (\Throwable $e) {
        return $e;
    }
}

$breaker = new CircuitBreaker($root);
$json = function ($d = ['id' => 1]) { return new Response(200, [], json_encode($d)); };
$connect = function () { return new ConnectException('Connection timed out', new Request('GET', 'orders/1')); };

// --- Ouverture
$breaker->reset();
$c = client([$connect(), $json()], $h);
$e1 = attempt(function () use ($c) { return $c->getOrder(1); });
$e2 = attempt(function () use ($c) { return $c->getOrder(1); });
ok('pas de réponse : le circuit s\'ouvre', $breaker->openSince() !== null);
ok('circuit ouvert : échec immédiat, sans requête', $e2 instanceof ApiUnavailableException && count($h) === 1,
    get_class($e2) . ', ' . count($h) . ' requête(s)');
ok('ApiUnavailableException est une ApiException', $e2 instanceof \WiziShop\SDK\Exception\ApiException);
ok('message avec l\'heure', is_object($e2) && strpos($e2->getMessage(), date('H:i', $breaker->openSince())) !== false, is_object($e2) ? $e2->getMessage() : '');

foreach ([502, 503, 504] as $code) {
    $breaker->reset();
    $c = client([new Response($code, [], '{}'), $json()], $h);
    attempt(function () use ($c) { return $c->getOrder(1); });
    ok("$code : le circuit s'ouvre", $breaker->openSince() !== null);
}

foreach ([500, 404, 422] as $code) {
    $breaker->reset();
    $c = client([new Response($code, [], '{}'), $json()], $h);
    attempt(function () use ($c) { return $c->getOrder(1); });
    ok("$code : le circuit reste fermé", $breaker->openSince() === null);
}

// --- Fermeture
$breaker->reset();
$breaker->trip();
$since = $breaker->openSince();
// Fin de la période d'ouverture simulée : « until » dans le passé, « since » gardé.
$cache = new \WiziShop\SDK\TokenCache(['root' => $root]);
$cache->set(CircuitBreaker::KEY, ['since' => $since - 300, 'until' => time() - 1], 600);
ok('période écoulée : circuit fermé pour une tentative', $breaker->openSince() === null);
$c = client([$connect()], $h);
attempt(function () use ($c) { return $c->getOrder(1); });
ok('nouvel échec : rouvert, « depuis » conservé', $breaker->openSince() === $since - 300);
$cache->set(CircuitBreaker::KEY, ['since' => $since - 300, 'until' => time() - 1], 600);
$c = client([$json()], $h);
$r = attempt(function () use ($c) { return $c->getOrder(1); });
ok('réponse reçue : circuit refermé et oublié', is_array($r) && $breaker->openSince() === null && $cache->get(CircuitBreaker::KEY) === null);

// --- Sans coupe-circuit (client construit à la main) : comportement 2.1.0
$breaker->reset();
$c = client([$connect(), $json()], $h, false);
attempt(function () use ($c) { return $c->getOrder(1); });
$r = attempt(function () use ($c) { return $c->getOrder(1); });
ok('client sans coupe-circuit : rien n\'est noté, la requête suivante part', $breaker->openSince() === null && is_array($r) && count($h) === 2);

// --- Fabrique : connexion refusée d'emblée quand le circuit est ouvert
$breaker->trip();
$t = microtime(true);
$e = attempt(function () use ($root) {
    return ApiClientFactory::authenticate('x', 'y', ['token_cache' => false, 'token_cache_root' => $root, 'base_uri' => 'http://10.255.255.1/']);
});
ok('authenticate(), circuit ouvert : refus immédiat', $e instanceof ApiUnavailableException && microtime(true) - $t < 1,
    sprintf('%.2f s', microtime(true) - $t));
ok('apiDownSince() rend « depuis »', ApiClientFactory::apiDownSince($root) === $breaker->openSince());
$breaker->reset();
ok('apiDownSince() : null, circuit fermé', ApiClientFactory::apiDownSince($root) === null);

// --- Délais réels
if (!in_array('--sans-reseau', $argv, true)) {
    // Connexion : une adresse non routable ne répond jamais.
    $breaker->reset();
    $c = new AuthenticatedApiClient(JWT::fromString($token), ['shopid' => 1, 'base_uri' => 'http://10.255.255.1/', 'circuit_breaker' => $breaker]);
    $t = microtime(true);
    $e = attempt(function () use ($c) { return $c->getOrder(1); });
    $d = microtime(true) - $t;
    ok('connexion impossible : abandon en 5 s', $d >= 4.5 && $d < 7 && is_object($e), sprintf('%.1f s', $d));
    ok('… et le circuit s\'ouvre', $breaker->openSince() !== null);

    // Requête : un serveur local qui accepte et ne répond jamais.
    $breaker->reset();
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $c = new AuthenticatedApiClient(JWT::fromString($token), ['shopid' => 1, 'base_uri' => "http://127.0.0.1:$port/", 'circuit_breaker' => $breaker]);
    $t = microtime(true);
    $e = attempt(function () use ($c) { return $c->getOrder(1); });
    $d = microtime(true) - $t;
    ok('réponse qui ne vient pas : abandon en 20 s', $d >= 19 && $d < 23 && is_object($e), sprintf('%.1f s', $d));
    ok('… et le circuit s\'ouvre', $breaker->openSince() !== null);
    fclose($server);
}

echo $failures ? "\n$failures échec(s)\n" : "\nTous les cas passent.\n";
exit($failures);
