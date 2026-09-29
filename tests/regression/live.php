<?php
/**
 * Harnais en ligne, LECTURE SEULE : les principales lectures du SDK sur une
 * vraie boutique. Aucune écriture.
 *
 *   WIZISHOP_LOGIN=… WIZISHOP_PASSWORD=… WIZISHOP_SHOP_ID=… \
 *   WIZISHOP_TEST_ORDER=… WIZISHOP_TEST_CUSTOMER=… WIZISHOP_TEST_PRODUCT=… WIZISHOP_TEST_SKU=… \
 *   php live.php <sdk/src> <projet/vendor/autoload.php>  > sortie.json
 *
 * WIZISHOP_TEST_ORDER doit être une commande facturée (pour la facture et le
 * bordereau) ; WIZISHOP_TEST_SKU, un SKU existant.
 *
 * À lancer avec l'ancienne et la nouvelle version à quelques secondes
 * d'intervalle, puis comparer. Un stock ou une date de mise à jour peuvent
 * bouger entre les deux passages : ce sont les seuls écarts admissibles.
 */

require __DIR__ . '/bootstrap.php';
sdk_bootstrap($argv[1], $argv[2]);

use WiziShop\SDK\ApiClientFactory;

function env($name)
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Variable d'environnement manquante : $name\n");
        exit(1);
    }

    return $value;
}

$client = ApiClientFactory::authenticate(env('WIZISHOP_LOGIN'), env('WIZISHOP_PASSWORD'), ['shopid' => env('WIZISHOP_SHOP_ID')]);

$order = (int) env('WIZISHOP_TEST_ORDER');
$customer = (int) env('WIZISHOP_TEST_CUSTOMER');
$product = (int) env('WIZISHOP_TEST_PRODUCT');
$sku = env('WIZISHOP_TEST_SKU');

// Les documents PDF sont régénérés à chaque appel : on compare leur taille et
// leur début, pas leur contenu.
function pdf($body)
{
    return ['length' => strlen($body), 'head' => substr($body, 0, 8)];
}

$calls = [
    'getOrder'                        => function ($c) use ($order) { return $c->getOrder($order); },
    'getOrder inexistante'            => function ($c) { return $c->getOrder(1); },
    'getOrders statut 5 (liste)'      => function ($c) { return array_column($c->getOrders(['status_code' => 5]), 'id'); },
    'getOrders page, période passée'  => function ($c) { return $c->getOrders(['limit' => 5, 'page' => 1, 'sort' => '-id', 'start_date' => '2026-09-01T00:00:00+02:00', 'end_date' => '2026-09-01T12:00:00+02:00']); },
    'getOrders total statut 35'       => function ($c) { $r = $c->getOrders(['status_code' => 35, 'limit' => 1]); return isset($r['total']) ? $r['total'] : null; },
    'getOrders du client'             => function ($c) use ($customer) { return array_column($c->getOrders(['customer_id' => $customer]), 'id'); },
    'getOrders client inconnu'        => function ($c) { return $c->getOrders(['customer_id' => 1]); },
    'getCustomer'                     => function ($c) use ($customer) { return $c->getCustomer($customer); },
    'getCustomers page'               => function ($c) { return $c->getCustomers(['page' => 1, 'limit' => 3]); },
    'getSku'                          => function ($c) use ($sku) { return $c->getSku($sku); },
    'getSku inexistant'               => function ($c) { return $c->getSku('ZZZZ-inexistant'); },
    'getProduct'                      => function ($c) use ($product) { return $c->getProduct($product); },
    'getProductCatalog'               => function ($c) use ($product) { return $c->getProductCatalog($product); },
    'getProductsCatalog'              => function ($c) use ($product) { return $c->getProductsCatalog([$product]); },
    'getCategory du produit'          => function ($c) use ($product) { return $c->getCategory($c->getProduct($product)['category_id']); },
    'getStats 2026-09-01'             => function ($c) { return $c->getStats(['from_date' => '2026-09-01T00:00:00', 'to_date' => '2026-09-01T23:59:59']); },
    'getInvoiceForOrder'              => function ($c) use ($order) { return pdf($c->getInvoiceForOrder($order)); },
    'getDeliverySlipForOrder'         => function ($c) use ($order) { return pdf($c->getDeliverySlipForOrder($order)); },
];

$out = [];
foreach ($calls as $name => $call) {
    ob_start();
    try {
        $result = sdk_describe($call($client));
    } catch (\Throwable $e) {
        $result = sdk_describe($e);
    }
    ob_end_clean();
    $out[$name] = $result;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
