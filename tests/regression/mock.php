<?php
/**
 * Harnais hors ligne : chaque méthode existante du SDK, face à des
 * réponses simulées — pagination, 404, erreurs serveur et réseau, encodage,
 * corps envoyés. Aucune requête ne part.
 *
 *   php mock.php <sdk/src> <projet/vendor/autoload.php>  > sortie.json
 *
 * À lancer avec l'ancienne et la nouvelle version, puis comparer les sorties.
 */

require __DIR__ . '/bootstrap.php';
sdk_bootstrap($argv[1], $argv[2]);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use WiziShop\SDK\AuthenticatedApiClient;
use WiziShop\SDK\Model\JWT;

function b64url($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$token = b64url('{"alg":"HS256","typ":"JWT"}') . '.' . b64url(json_encode(['id_shop' => 999, 'exp' => time() + 3600])) . '.sig';

function json($data, $status = 200)
{
    return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
}

function page($results, $page, $pages)
{
    return json(['results' => $results, 'page' => $page, 'pages' => $pages, 'limit' => 100, 'total' => 999]);
}

$out = [];

function scenario($name, array $queue, callable $call)
{
    global $out, $token;

    $history = [];
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));
    $client = new AuthenticatedApiClient(JWT::fromString($token), ['shopid' => 12345, 'handler' => $stack]);

    ob_start();
    try {
        $result = sdk_describe($call($client));
    } catch (\Throwable $e) {
        $result = sdk_describe($e);
    }
    $printed = ob_get_clean();

    $requests = [];
    foreach ($history as $transaction) {
        $request = $transaction['request'];
        $requests[] = $request->getMethod() . ' ' . $request->getUri() . ($request->getBody()->getSize() ? ' ' . $request->getBody() : '');
    }

    $out[$name] = ['requests' => $requests, 'outcome' => $result, 'printed' => $printed !== ''];
}

$e404 = new Response(404, [], '{"message":"Not found"}');
$e500 = new Response(500, [], '{"message":"Boom"}');
$connect = new ConnectException('Connection refused', new Request('GET', 'orders'));

// --- Listes et pagination
scenario('getOrders, 3 pages', [page([['id' => 1]], 1, 3), page([['id' => 2]], 2, 3), page([['id' => 3]], 3, 3)],
    function ($c) { return $c->getOrders(['status_code' => 20, 'sort' => '-id']); });
scenario('getOrders, limit => une page brute', [page([['id' => 1]], 1, 9)],
    function ($c) { return $c->getOrders(['status_code' => 5, 'limit' => 1]); });
scenario('getOrders, page seule', [page([['id' => 1]], 2, 9)],
    function ($c) { return $c->getOrders(['page' => 2, 'sort' => '-id', 'query' => 'dupont durand']); });
scenario('getOrders, 404 => []', [$e404], function ($c) { return $c->getOrders(['customer_id' => 1]); });
scenario('getOrders, limit + 404 => []', [$e404], function ($c) { return $c->getOrders(['limit' => 1]); });
scenario('getOrders, 404 en page 2 => []', [page([['id' => 1]], 1, 2), $e404], function ($c) { return $c->getOrders([]); });
scenario('getOrders, page vide', [json([])], function ($c) { return $c->getOrders([]); });
scenario('getOrders, 500', [$e500], function ($c) { return $c->getOrders(['status_code' => 25]); });
scenario('getOrders, status_code hors bornes', [], function ($c) { return $c->getOrders(['status_code' => 60]); });
scenario('getOrders, dates ISO en chaîne', [page([], 1, 1)],
    function ($c) { return $c->getOrders(['start_date' => '2026-09-01T00:00:00+02:00', 'end_date' => '2026-09-29T23:59:59+02:00', 'sort' => 'date']); });
scenario('getOrders, dates DateTime', [page([], 1, 1)],
    function ($c) { return $c->getOrders(['start_date' => new \DateTime('2026-09-01 08:00:00'), 'end_date' => new \DateTime('2026-09-02 09:30:00')]); });
scenario('getCustomers, since_registration', [page([['id' => 7]], 1, 1)],
    function ($c) { return $c->getCustomers(['since_registration' => '2026-09-29T10:00:00+02:00']); });
scenario('getSkus, 2 pages', [page([['sku' => 'A']], 1, 2), page([['sku' => 'B']], 2, 2)], function ($c) { return $c->getSkus([]); });
scenario('getDetailedSkus', [page([], 1, 1)], function ($c) { return $c->getDetailedSkus(['sku' => 'X']); });
scenario('getNewsletterSubscribers', [page([], 1, 1)], function ($c) { return $c->getNewsletterSubscribers(['limit' => 5]); });
scenario('getBrands', [page([['id' => 1]], 1, 1)], function ($c) { return $c->getBrands(); });
scenario('getCategories', [page([['id' => 1]], 1, 1)], function ($c) { return $c->getCategories(); });
scenario('getProducts', [page([['id' => 1]], 1, 1)], function ($c) { return $c->getProducts(['status' => 'visible']); });

// --- Lectures unitaires
scenario('getOrder', [json(['id' => 5, 'status_code' => 20])], function ($c) { return $c->getOrder(5); });
scenario('getOrder, id en chaîne', [json(['id' => 5])], function ($c) { return $c->getOrder('5'); });
scenario('getOrder, 404 lève', [$e404], function ($c) { return $c->getOrder(5); });
scenario('getOrder, 500 lève', [$e500], function ($c) { return $c->getOrder(5); });
scenario('getOrder, erreur réseau', [$connect], function ($c) { return $c->getOrder(5); });
scenario('getCustomer', [json(['id' => 7])], function ($c) { return $c->getCustomer(7); });
scenario('getSku, encodage', [json(['sku' => 'A #b', 'stock' => 3])], function ($c) { return $c->getSku('A #b/c'); });
scenario('getSku, 404 lève (repli -stock)', [$e404], function ($c) { return $c->getSku('ABC1-stock'); });
scenario('getProduct', [json(['id' => 84])], function ($c) { return $c->getProduct(84); });
scenario('getCategory', [json(['id' => 3])], function ($c) { return $c->getCategory(3); });
scenario('getBrand', [json(['id' => 3])], function ($c) { return $c->getBrand(3); });
scenario('getProductCatalog', [json(['products' => [['id' => 84]]])], function ($c) { return $c->getProductCatalog(84); });
scenario('getProductCatalog, 404 => []', [$e404], function ($c) { return $c->getProductCatalog(84); });
scenario('getProductsCatalog', [json(['products' => []])], function ($c) { return $c->getProductsCatalog([84, 3, 12]); });
scenario('getProductSelection', [json(['products' => []])], function ($c) { return $c->getProductSelection(['type' => 'new', 'page' => 1, 'limit' => 10]); });
scenario('getProductSearch', [json(['products' => []])], function ($c) { return $c->getProductSearch(['search' => 'poivre', 'page' => 1, 'limit' => 10]); });

// --- Statistiques
scenario('getStats, chaînes', [json(['total_orders' => 3])],
    function ($c) { return $c->getStats(['from_date' => '2026-09-29T00:00:00', 'to_date' => '2026-09-29T23:59:59']); });
scenario('getStats, DateTime', [json(['total_orders' => 3])],
    function ($c) { return $c->getStats(['from_date' => new \DateTime('2026-09-01 00:00:00'), 'to_date' => new \DateTime('2026-09-30 23:59:59')]); });

// --- Écritures (simulées)
scenario('createOrder', [json(['id' => 10, 'public_id' => '11'], 201)], function ($c) { return $c->createOrder(['customer_id' => 1, 'status' => 5]); });
scenario('createOrder, 422', [new Response(422, [], '{"message":"Invalid"}')], function ($c) { return $c->createOrder([]); });
scenario('updateSkuStock', [json(['sku' => 'A', 'stock' => 10])], function ($c) { return $c->updateSkuStock('A B', 10, 'decrease'); });
scenario('updateSkuStock, défaut replace', [json([])], function ($c) { return $c->updateSkuStock('A', 3); });
scenario('updateSkuStock, méthode invalide', [], function ($c) { return $c->updateSkuStock('A', 3, 'add'); });
scenario('updateSkuStock, 404 lève', [$e404], function ($c) { return $c->updateSkuStock('A-stock', 3, 'increase'); });
scenario('createProduct', [json(['id' => 1], 201)], function ($c) { return $c->createProduct(['name' => 'X']); });
scenario('updateProduct', [json(['id' => 1])], function ($c) { return $c->updateProduct(1, ['name' => 'X']); });
scenario('createBrand', [json(['id' => 1], 201)], function ($c) { return $c->createBrand('B', 'http://i'); });
scenario('updateBrand', [json(['id' => 1])], function ($c) { return $c->updateBrand(1, 'B', 'b', null); });
scenario('deleteBrand 204', [new Response(204)], function ($c) { return $c->deleteBrand(1); });
scenario('deleteBrand 200', [new Response(200)], function ($c) { return $c->deleteBrand(1); });
scenario('setTag 204', [new Response(204)], function ($c) { return $c->setTag(1, ['value' => 't']); });

// --- Statuts
foreach (['cancelOrder', 'pendingPaymentOrder', 'pendingPaymentVerificationOrder', 'pendingReplenishmentOrder',
          'pendingPreparationOrder', 'preparingOrder', 'delayingOrder', 'deliveredOrder', 'returnOrder',
          'returnedOrder', 'refundedOrder'] as $method) {
    scenario($method, [json(['id' => 1, 'status_code' => 0])], function ($c) use ($method) { return $c->$method(123); });
}
scenario('cancelOrder, 404 lève', [$e404], function ($c) { return $c->cancelOrder(123); });
scenario('shipOrder', [json(['id' => 1])],
    function ($c) { return $c->shipOrder(123, ['tracking_numbers' => [['shipping_id' => 39, 'tracking_number' => 'XV', 'tracking_url' => 'http://t']]]); });
scenario('customStatusOrder', [json(['id' => 1])], function ($c) { return $c->customStatusOrder(123, '21'); });

// --- Documents
scenario('getInvoiceForOrder', [new Response(200, ['Content-Type' => 'application/pdf'], '%PDF-1.4 facture')], function ($c) { return $c->getInvoiceForOrder(123); });
scenario('getInvoiceForOrder, vide', [new Response(200, [], '')], function ($c) { return $c->getInvoiceForOrder(123); });
scenario('getDeliverySlipForOrder', [new Response(200, [], '%PDF-1.4 bordereau')], function ($c) { return $c->getDeliverySlipForOrder(123); });
scenario('getPickingSlipForOrder', [new Response(200, [], '%PDF-1.4 picking')], function ($c) { return $c->getPickingSlipForOrder(123); });
scenario('getDeliverySlipForOrder, 404 lève', [$e404], function ($c) { return $c->getDeliverySlipForOrder(123); });

// --- Guzzle brut, comme inc/manorders.php et ajax/actions/manorder.php
scenario('post brut customers', [json(['id' => 1001], 201)],
    function ($c) { return json_decode((string) $c->post('customers', ['json' => ['email' => 'a@b.c']])->getBody(), true); });
scenario('post brut, 422 lève', [new Response(422, [], '{"message":"x"}')],
    function ($c) { return $c->post('orders', ['json' => []]); });

// --- En-têtes et adresse de base
scenario('en-têtes', [json([])], function ($c) {
    $c->getOrder(1);
    return ['base' => (string) $c->getConfig('base_uri'), 'auth' => substr($c->getConfig('headers')['Authorization'], 0, 7)];
});

// Comportement volontairement changé : une erreur réseau pendant une liste
// levait une Error PHP (null->getStatusCode()) ; elle lève désormais ApiException.
scenario('CHANGÉ getOrders, erreur réseau', [$connect], function ($c) { return $c->getOrders([]); });

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
