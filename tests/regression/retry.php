<?php
/**
 * Harnais hors ligne des relances (v2.1.0) : 429 et 401, face à des réponses
 * simulées. Aucune requête ne part. Chaque cas vérifie ce qui est attendu et
 * affiche OK ou ÉCHEC ; le code de sortie vaut le nombre d'échecs.
 *
 *   php retry.php <sdk/src> <projet/vendor/autoload.php>
 */

require __DIR__ . '/bootstrap.php';
sdk_bootstrap($argv[1], $argv[2]);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use WiziShop\SDK\AuthenticatedApiClient;
use WiziShop\SDK\Model\JWT;

function b64url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function token($name)
{
    return b64url('{"alg":"HS256","typ":"JWT"}') . '.' . b64url(json_encode(['id_shop' => 999, 'exp' => time() + 3600, 'n' => $name])) . '.sig';
}

function ok($data = ['id' => 1])
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($data));
}

function r429($after = '0')
{
    return new Response(429, $after === null ? [] : ['Retry-After' => $after], '{"message":"Too Many Requests"}');
}

function r401()
{
    return new Response(401, [], '{"message":"Unauthorized"}');
}

$failures = 0;

/**
 * @param array $queue réponses simulées
 * @param array $config réglages du client
 * @param callable $call l'appel
 * @param callable $check function ($résultat|$exception, $requêtes, $durée) : bool
 */
function check($name, array $queue, array $config, callable $call, callable $check)
{
    global $failures;

    $history = [];
    $stack = HandlerStack::create(new MockHandler($queue));
    $client = new AuthenticatedApiClient(JWT::fromString(token('ancien')), ['shopid' => 12345, 'handler' => $stack] + $config);
    // Poussé après le client : au plus près du transport, il voit chaque tentative.
    $stack->push(Middleware::history($history));

    $start = microtime(true);
    try {
        $outcome = $call($client);
    } catch (\Throwable $e) {
        $outcome = $e;
    }
    $duration = microtime(true) - $start;

    $requests = [];
    foreach ($history as $transaction) {
        $auth = $transaction['request']->getHeaderLine('Authorization');
        $payload = json_decode(base64_decode(strtr(explode('.', substr($auth, 7))[1], '-_', '+/')), true);
        $requests[] = ['method' => $transaction['request']->getMethod(), 'token' => $payload['n'] ?? '?',
                       'status' => $transaction['response'] ? $transaction['response']->getStatusCode() : null];
    }

    $passed = (bool) $check($outcome, $requests, $duration);
    $failures += $passed ? 0 : 1;
    printf("%-6s %s — %d requête(s) : %s, %.1f s\n", $passed ? 'OK' : 'ÉCHEC', $name, count($requests),
        implode(' ', array_map(function ($r) { return $r['status'] . '/' . $r['token']; }, $requests)), $duration);
}

$isException = function ($o, $status) {
    return $o instanceof \Throwable && method_exists($o, 'getResponse') && $o->getResponse() && $o->getResponse()->getStatusCode() === $status;
};

// --- 429
check('429 puis 200 : relancé, résultat rendu', [r429(), ok()], [], function ($c) { return $c->getOrder(1); },
    function ($o, $r) { return is_array($o) && $o['id'] === 1 && count($r) === 2; });

check('429 ×3 puis 200 : trois relances suffisent', [r429(), r429(), r429(), ok()], [], function ($c) { return $c->getOrder(1); },
    function ($o, $r) { return is_array($o) && count($r) === 4; });

check('429 ×4 : abandon après trois relances, ApiException 429', [r429(), r429(), r429(), r429(), ok()], [], function ($c) { return $c->getOrder(1); },
    function ($o, $r) use ($isException) { return $isException($o, 429) && count($r) === 4; });

check('429 sur une liste : relancé, liste rendue', [r429(), ok(['results' => [['id' => 7]], 'page' => 1, 'pages' => 1])], [],
    function ($c) { return $c->getOrders(['status_code' => 20]); },
    function ($o, $r) { return $o === [['id' => 7]] && count($r) === 2; });

check('429 en POST (non traité) : relancé', [r429(), ok(['id' => 9])], [],
    function ($c) { return json_decode((string) $c->post('customers', ['json' => ['email' => 'a@b.c']])->getBody(), true); },
    function ($o, $r) { return $o === ['id' => 9] && count($r) === 2 && $r[1]['method'] === 'POST'; });

check('Retry-After: 1 respecté', [r429('1'), ok()], [], function ($c) { return $c->getOrder(1); },
    function ($o, $r, $d) { return is_array($o) && $d >= 0.9; });

check('sans Retry-After : 1 s', [r429(null), ok()], [], function ($c) { return $c->getOrder(1); },
    function ($o, $r, $d) { return is_array($o) && $d >= 0.9 && $d < 3; });

check('retry_429 => 0 : aucune relance', [r429(), ok()], ['retry_429' => 0], function ($c) { return $c->getOrder(1); },
    function ($o, $r) use ($isException) { return $isException($o, 429) && count($r) === 1; });

$logged = [];
check('on_retry appelé à chaque relance', [r429(), r429(), ok()],
    ['on_retry' => function ($reason, $attempt) use (&$logged) { $logged[] = "$reason#$attempt"; }],
    function ($c) { return $c->getOrder(1); },
    function ($o) use (&$logged) { return is_array($o) && $logged === ['429#1', '429#2']; });

// --- 401
$reauth = function () { return JWT::fromString(token('neuf')); };

check('401 avec reconnexion : relancé avec le nouveau jeton', [r401(), ok()], ['reauthenticate' => $reauth],
    function ($c) { return $c->getOrder(1); },
    function ($o, $r) { return is_array($o) && count($r) === 2 && $r[0]['token'] === 'ancien' && $r[1]['token'] === 'neuf'; });

check('après reconnexion, les requêtes suivantes portent le nouveau jeton', [r401(), ok(), ok()], ['reauthenticate' => $reauth],
    function ($c) { $c->getOrder(1); return $c->getOrder(2); },
    function ($o, $r) { return is_array($o) && count($r) === 3 && $r[2]['token'] === 'neuf'; });

check('401 qui persiste : une seule reconnexion, ApiException 401', [r401(), r401(), ok()], ['reauthenticate' => $reauth],
    function ($c) { return $c->getOrder(1); },
    function ($o, $r) use ($isException) { return $isException($o, 401) && count($r) === 2; });

check('401 sans moyen de se reconnecter : ApiException 401, comme avant', [r401(), ok()], [],
    function ($c) { return $c->getOrder(1); },
    function ($o, $r) use ($isException) { return $isException($o, 401) && count($r) === 1; });

check('reconnexion en échec : ApiException 401', [r401(), ok()],
    ['reauthenticate' => function () { throw new \RuntimeException('login refusé'); }],
    function ($c) { return $c->getOrder(1); },
    function ($o, $r) use ($isException) { return $isException($o, 401) && count($r) === 1; });

// --- Rien ne change ailleurs
check('404 : pas de relance', [new Response(404, [], '{}'), ok()], ['reauthenticate' => $reauth],
    function ($c) { return $c->getOrder(1); },
    function ($o, $r) use ($isException) { return $isException($o, 404) && count($r) === 1; });

check('500 : pas de relance', [new Response(500, [], '{}'), ok()], ['reauthenticate' => $reauth],
    function ($c) { return $c->getOrder(1); },
    function ($o, $r) use ($isException) { return $isException($o, 500) && count($r) === 1; });

check('200 : une requête, jeton d\'origine', [ok()], [], function ($c) { return $c->getOrder(1); },
    function ($o, $r) { return is_array($o) && count($r) === 1 && $r[0]['token'] === 'ancien'; });

echo $failures ? "\n$failures échec(s)\n" : "\nTous les cas passent.\n";
exit($failures);
