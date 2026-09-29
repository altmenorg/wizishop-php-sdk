<?php

namespace WiziShop\SDK;

use GuzzleHttp\Exception\RequestException;
use WiziShop\SDK\Exception\ApiException;
use WiziShop\SDK\Model\JWT;

class AuthenticatedApiClient extends \GuzzleHttp\Client
{
    /**
     * @const string SDK version
     */
    const VERSION = '1.0.4';

    /**
     * @const string API URL (ending with /)
     */
    const API_URL = 'https://api.wizishop.com/';

    /**
     * @var JWT Json Web Token
     */
    private $jwt;

    public function __construct(JWT $jwt, array $config = [])
    {
        $this->jwt = $jwt;
        $shopId = $config["shopid"]; 
        $apiUrl = isset($config['base_uri']) ? $config['base_uri'] : self::API_URL;
        $baseUri = $apiUrl . 'v3/' . ($shopId ? sprintf('shops/%s/', $shopId) : '');
        $defaultConfig = [
            'base_uri' => $baseUri,
            'headers' => [
                'User-Agent' => sprintf('%s wizishop-php-sdk/%s', \GuzzleHttp\default_user_agent(), self::VERSION),
                'Authorization' => 'Bearer ' . $this->jwt->getToken()
            ]
        ];

        parent::__construct($defaultConfig + $config);
    }

    /**
     * @param callable $call Closure to get a json formatted result page for a page number
     * @param callable $parse Closure to get an array of results from the result page
     *
     * @return array The collection of the total pages
     */
    private function assembleResults(callable $call, callable $parse)
    {
        $currentPage = 1;
        $results = [];

        do {
            $resultPage = $call($currentPage);

            if (empty($resultPage)) {
                return [];
            }

            $results = array_merge($results, $parse($resultPage));

            $currentPage++;
        } while ($currentPage <= $resultPage['pages']);

        return $results;
    }

    /**
     * @param string $route Route
     * @param array $params
     *
     * @return array Results
     */
    private function getAllResultsForRoute($route, array $params = [])
    {
        try {
            if (array_key_exists('page', $params) || array_key_exists('limit', $params)) {
                return $this->getSingleResultForRoute($route, [
                    'query' => $params
                ]);
            }

            return $this->assembleResults(
                function ($page) use ($route, $params) {
                    $response = $this->get($route, [
                        'query' => [
                            'limit' => 100,
                            'page' => $page
                        ] + $params
                    ]);

                    return json_decode($response->getBody(), true);
                },
                function ($resultPage) {
                    return $resultPage['results'];
                }
            );
        } catch (RequestException $e) {
            print_r($e->getResponse());
            if (404 == $e->getResponse()->getStatusCode()) { // If no result, the API returns 404
                return [];
            }

            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * @param string $route
     * @param array $params
     *
     * @return array Result
     */
    private function getSingleResultForRoute($route, array $params = [])
    {
        try {
            $response = $this->get($route, $params);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * @return JWT Json Web Token
     */
    public function getJWT()
    {
        return $this->jwt;
    }

    /**
     * @param int $brandId
     * @param array $params
     *
     * @return array Brand
     */
    public function getBrand($brandId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('brands/%s', $brandId), $params);
    }

    /**
     * @param array $params
     *
     * @return array Brands
     */
    public function getBrands(array $params = [])
    {
        return $this->getAllResultsForRoute('brands', $params);
    }

    /**
     * @param int $categoryId
     * @param array $params
     *
     * @return array Brand
     */
    public function getCategory($catId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('categories/%s', $catId), $params);
    }

    /**
     * @param array $params
     *
     * @return array Categories
     */
    public function getCategories(array $params = [])
    {
        return $this->getAllResultsForRoute('categories', $params);
    }
        /**
     * @param int $categoryId
     *
     * @return array Brand
     */
    public function getCategoryProducts($catId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('categories/%s/products', $catId));
    }
    /**
     * @param int $customerId
     * @param array $params
     *
     * @return array Customer
     */
    public function getCustomer($customerId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('customers/%s', $customerId), $params);
    }

    /**
     * @param array $params
     *
     * @return array Customers
     */
    public function getCustomers(array $params = [])
    {
        return $this->getAllResultsForRoute('customers', $params);
    }

    /**
     * @param array $params
     *
     * @return array NewsletterSubscribers
     */
    public function getNewsletterSubscribers(array $params = [])
    {
        return $this->getAllResultsForRoute('newsletter/subscribers', $params);
    }

    /**
     * @param string $sku
     * @param array $params
     *
     * @return array Sku
     */
    public function getSku($sku, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('skus/%s', rawurlencode($sku)), $params);
    }

    /**
     * @param array $params
     *
     * @return array Skus
     */
    public function getSkus(array $params = [])
    {
        return $this->getAllResultsForRoute('skus', $params);
    }

    /**
     * @param array $params
     *
     * @return array Skus
     */
    public function getDetailedSkus(array $params = [])
    {
        return $this->getAllResultsForRoute('skus', ['detailed' => 1] + $params);
    }

    /**
     * @param string $sku SKU
     * @param int $stock Stock value
     * @param string $method How to update the stock value, can be either "replace" (default), "increase" or "decrease"
     *
     * @return array Sku
     */
    public function updateSkuStock($sku, $stock, $method = 'replace')
    {
        if (!in_array($method, ['replace', 'increase', 'decrease'])) {
            throw new \InvalidArgumentException('Update stock method cannot be ' . $method);
        }

        try {
            $response = $this->put(sprintf('skus/%s', rawurlencode($sku)), [
                'json' => [
                    'method' => $method,
                    'stock' => $stock
                ]
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * @param string $brandId
     * @param string $newName
     * @param string $newUrl
     * @param string $newImageUrl
     *
     * @return array Brand
     */
    public function updateBrand($brandId, $newName, $newUrl = null, $newImageUrl = null)
    {
        try {
            $fields = [
                'name' => $newName
            ];

            if ($newUrl) {
                $fields['url'] = $newUrl;
            }

            if ($newImageUrl) {
                $fields['image_url'] = $newImageUrl;
            }

            $response = $this->put(sprintf('brands/%s', $brandId), [
                'json' => $fields
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * @param string $name
     * @param string $newImageUrl
     *
     * @return array Brand
     */
    public function createBrand($name, $newImageUrl = null)
    {
        try {
            $fields = [
                'name' => $name
            ];

            if ($newImageUrl) {
                $fields['image_url'] = $newImageUrl;
            }

            $response = $this->post('brands', [
                'json' => $fields
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * @param int $brandId
     *
     * @return bool
     */
    public function deleteBrand($brandId)
    {
        try {
            $response = $this->delete(sprintf('brands/%s', $brandId));

            return 204 == $response->getStatusCode();
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * @param array $params Array of params
     *                      status_code: Order status code ( abandoned: 0, pending payment: 5, payment awaiting verification: 10, awaiting replenishment: 11, awaiting preparation: 20, preparing: 25, partially sent: 29, sent: 30, delivered: 35, being returned: 40, returned: 45, refunded: 46, canceled: 50)
     *                      start_date: Start date
     *                      end_date: End date
     *
     * @return array Orders
     */
    public function getOrders(array $params = [])
    {
        if (array_key_exists('status_code', $params) && ($params['status_code'] < 0 || $params['status_code'] > 50)) {
            throw new \InvalidArgumentException('Order status code should be between 0 and 50');
        }

        if (array_key_exists('start_date', $params) && $params['start_date'] instanceof \DateTime) {
            $params['start_date'] = $params['start_date']->format('Y-m-d H:i:s');
        }

        if (array_key_exists('end_date', $params) && $params['end_date'] instanceof \DateTime) {
            $params['end_date'] = $params['end_date']->format('Y-m-d H:i:s');
        }

        return $this->getAllResultsForRoute('orders', $params);
    }

    public function getOrder($orderId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('orders/%s', $orderId), $params);
    }

    public function getStats(array $params = [])
    {

        if (array_key_exists('from_date', $params) && $params['from_date'] instanceof \DateTime) {
            $params['from_date'] = $params['from_date']->format('Y-m-d H:i:s');
        }

        if (array_key_exists('to_date', $params) && $params['to_date'] instanceof \DateTime) {
            $params['to_date'] = $params['to_date']->format('Y-m-d H:i:s');
        }


	// get-stats method seems to be parsed as fuck by wizishop... Standard formats do not work
	// and using standard way to pass array as parameter is fucked aswell...
        try {
            $response = $this->get('order-stats?from_date='.$params['from_date'].'&to_date='.$params['to_date']);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }

    }

    public function updateProduct($productId, array $params = [])
    {
        try {
            $response = $this->put(sprintf('products/%s', $productId), [
                'json' => $params
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }
    
    public function createProduct(array $params = [])
    {
        try {
            $response = $this->post('products', [
                'json' => $params
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    public function createOrder(array $params = [])
    {
        try {
            $response = $this->post('orders', [
                'json' => $params
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    public function getProducts(array $params = [])
    {
        return $this->getAllResultsForRoute('products', $params);
    }

    /**
     * @param int $orderId Order id
     * @param array $params
     *
     * @return product objects
     */    
    public function getProduct($productId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('products/%s', $productId), $params);
    }
         
    /**
     * @param array $params=(type => flash/discount/new, page => page, limit => limit)
     *
     * @return product objects
     */
    public function getProductSelection(array $params = [])
    {
        return $this->getAllResultsForRoute('catalog-selection', $params);
    }

    /**
     * @param int $orderId Order id
     *
     * @return product object
     */
    public function getProductCatalog($productId)
    {
        return $this->getAllResultsForRoute('catalog-specific', array("prodIds" => json_encode(array($productId)), "page" => 1, "limit" => 1));
    }

    /**
     * @param int $orderId Order id
     *
     * @return product object
     */
    public function getProductsCatalog($productIds)
    {
        return $this->getAllResultsForRoute('catalog-specific', array("prodIds" => json_encode($productIds), "page" => 1, "limit" => 200));
    }

    /**
     * @param array $params=(search => search, page => page, limit => limit)
     *      *
     * @return product object
     */
    public function getProductSearch(array $params = [])
    {
        return $this->getAllResultsForRoute('catalog-search', $params);
    }

    public function getInvoiceForOrder($orderId, array $params = [])
    {
        try {
            $response = $this->get(sprintf('orders/%s/invoice', $orderId), $params);

            return (string) $response->getBody();
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * @param int $orderId Order id
     * @param array $params
     *
     * @return mixed PDF data to write
     */
    public function getPickingSlipForOrder($orderId, array $params = [])
    {
        try {
            $response = $this->get(sprintf('orders/%s/picking_slip', $orderId), $params);

            return (string) $response->getBody();
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * @param int $orderId Order id
     * @param array $params
     *
     * @return mixed PDF data to write
     */
    public function getDeliverySlipForOrder($orderId, array $params = [])
    {
        try {
            $response = $this->get(sprintf('orders/%s/delivery_slip', $orderId), $params);

            return (string) $response->getBody();
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "canceled" (status_code: 50)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function cancelOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/cancel', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "pending payment" (status_code: 5)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function pendingPaymentOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/pending_payment', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "pending payment verification" (status_code: 10)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function pendingPaymentVerificationOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/pending_payment_verification', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "pending replenishment" (status_code: 11)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function pendingReplenishmentOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/pending_replenishment', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "pending preparation" (status_code: 20)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function pendingPreparationOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/pending_preparation', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "preparing" (status_code: 25)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function preparingOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/preparing', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "delayed" (status_code: 29)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function delayingOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/partially_sent', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "sent" (status_code: 30)
     *
     * @param int $orderId Order id
     * @param array $trackingNumbers Tracking numbers
     *                               Example: [
     *                                   'tracking_numbers' => [
     *                                       [
     *                                           'shipping_id' => 39,
     *                                           'tracking_number' => 'XVBFD-2'
     *                                       ]
     *                                   ]
     *                               ]
     *
     * @return array Order details with the new status
     */
    public function shipOrder($orderId, array $trackingNumbers)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/ship', $orderId), [
              'json' => $trackingNumbers
            ]);
            
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "delivered" (status_code: 35)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function deliveredOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/delivered', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "being returned" (status_code: 40)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function returnOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/return', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "returned" (status_code: 45)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function returnedOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/returned', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to "refunded" (status_code: 46)
     *
     * @param int $orderId Order id
     *
     * @return array Order details with the new status
     */
    public function refundedOrder($orderId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/status/refunded', $orderId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }


    /**
     * Sets the order Tag
     *
     * @param int $orderId Order id
     * @param array $tag
     *                               Example: [
     *                                   'value' => 'mytag'
     *                                       ]
     * @return array Order details with the new status
     */
    public function setTag($orderId, array $tag)
    {
        try {
            $response = $this->put(sprintf('orders/%s/tag', $orderId), [
              'json' => $tag
            ]);

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }

    /**
     * Changes order status to custom status
     *
     * @param int $orderId Order id
     * @param int $statusId Status id
     *
     * @return array Order details with the new status
     */    
    public function customStatusOrder($orderId, $statusId)
    {
        try {
            $response = $this->put(sprintf('orders/%s/custom_status/%s', $orderId, $statusId));

            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            throw new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse());
        }
    }  

}
