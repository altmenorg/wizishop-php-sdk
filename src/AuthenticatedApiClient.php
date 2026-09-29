<?php

namespace WiziShop\SDK;

use GuzzleHttp\Exception\RequestException;
use WiziShop\SDK\Api;
use WiziShop\SDK\Exception\ApiException;
use WiziShop\SDK\Model\JWT;

/**
 * Client de l'API Wizishop v3, déjà authentifié et réglé sur une boutique.
 *
 * C'est un client Guzzle : ses méthodes get(), post(), put() et delete()
 * restent utilisables avec un chemin relatif à la boutique (« orders/123 »),
 * pour un point d'accès que le SDK ne couvrirait pas encore.
 *
 * Les méthodes sont rangées par ressource dans src/Api/, sous forme de traits :
 * elles restent toutes des méthodes de cette classe, avec les noms et les
 * signatures des versions précédentes.
 *
 * Deux comportements d'erreur, hérités et conservés tels quels :
 *  - une liste (getOrders, getCustomers…) rend [] quand l'API répond 404 —
 *    c'est ainsi qu'elle signale une liste vide ;
 *  - une lecture unitaire (getOrder, getSku…) lève ApiException sur un 404.
 */
class AuthenticatedApiClient extends \GuzzleHttp\Client
{
    use Api\Brands;
    use Api\Categories;
    use Api\Comments;
    use Api\Customers;
    use Api\Newsletter;
    use Api\OrderCustomStates;
    use Api\OrderStatuses;
    use Api\Orders;
    use Api\Products;
    use Api\Scripts;
    use Api\Shop;
    use Api\Skus;
    use Api\StoreCredits;
    use Api\Webhooks;

    /**
     * @const string SDK version
     */
    const VERSION = '2.0.0';

    /**
     * @const string API URL (ending with /)
     */
    const API_URL = 'https://api.wizishop.com/';

    /**
     * Taille de page utilisée pour rassembler une liste complète.
     */
    const PAGE_SIZE = 100;

    /**
     * @var JWT Json Web Token
     */
    private $jwt;

    /**
     * @param JWT $jwt
     * @param array $config Réglages Guzzle, plus « shopid » : l'identifiant de
     *                      la boutique. À défaut, celui que porte le jeton.
     */
    public function __construct(JWT $jwt, array $config = [])
    {
        $this->jwt = $jwt;

        $shopId = isset($config['shopid']) && $config['shopid'] ? $config['shopid'] : $jwt->get('id_shop');
        $apiUrl = isset($config['base_uri']) ? $config['base_uri'] : self::API_URL;
        $baseUri = $apiUrl . 'v3/' . ($shopId ? sprintf('shops/%s/', $shopId) : '');

        $defaultConfig = [
            'base_uri' => $baseUri,
            'headers' => [
                'User-Agent' => sprintf('%s wizishop-php-sdk/%s', self::defaultUserAgent(), self::VERSION),
                'Authorization' => 'Bearer ' . $this->jwt->getToken(),
            ],
        ];

        parent::__construct($defaultConfig + $config);
    }

    /**
     * @return JWT Json Web Token
     */
    public function getJWT()
    {
        return $this->jwt;
    }

    /**
     * Guzzle 6 expose une fonction, Guzzle 7 une méthode statique.
     *
     * @return string
     */
    private static function defaultUserAgent()
    {
        if (class_exists('GuzzleHttp\Utils') && method_exists('GuzzleHttp\Utils', 'defaultUserAgent')) {
            return \GuzzleHttp\Utils::defaultUserAgent();
        }

        return \GuzzleHttp\default_user_agent();
    }

    /**
     * Transforme une erreur Guzzle en ApiException, en gardant la requête, la
     * réponse (absente sur une erreur réseau) et l'exception d'origine.
     *
     * @param RequestException $e
     *
     * @return ApiException
     */
    protected function toApiException(RequestException $e)
    {
        if ($e instanceof ApiException) {
            return $e;
        }

        return new ApiException($e->getMessage(), $e->getRequest(), $e->getResponse(), $e);
    }

    /**
     * Une requête dont on veut le corps JSON décodé.
     *
     * @param string $method
     * @param string $route
     * @param array $options Options de requête Guzzle
     *
     * @return mixed
     *
     * @throws ApiException
     */
    protected function requestJson($method, $route, array $options = [])
    {
        try {
            $response = $this->request($method, $route, $options);
        } catch (RequestException $e) {
            throw $this->toApiException($e);
        }

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Une requête dont on veut le corps brut — un PDF, par exemple.
     *
     * @param string $route
     * @param array $options
     *
     * @return string
     *
     * @throws ApiException
     */
    protected function requestRaw($route, array $options = [])
    {
        try {
            return (string) $this->request('GET', $route, $options)->getBody();
        } catch (RequestException $e) {
            throw $this->toApiException($e);
        }
    }

    /**
     * Une requête dont seul compte le succès (204 No Content, en général).
     *
     * @param string $method
     * @param string $route
     * @param array $options
     *
     * @return bool
     *
     * @throws ApiException
     */
    protected function requestStatus($method, $route, array $options = [])
    {
        try {
            $response = $this->request($method, $route, $options);
        } catch (RequestException $e) {
            throw $this->toApiException($e);
        }

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /**
     * Lecture d'une ressource.
     *
     * $params est un tableau d'options Guzzle (« query », notamment), comme
     * dans les versions précédentes — et non une liste de paramètres de requête.
     *
     * @param string $route
     * @param array $params
     *
     * @return array|null
     *
     * @throws ApiException y compris sur un 404
     */
    protected function getSingleResultForRoute($route, array $params = [])
    {
        return $this->requestJson('GET', $route, $params);
    }

    /**
     * Lecture d'une liste.
     *
     * Sans « page » ni « limit » dans $params, toutes les pages sont
     * parcourues et rassemblées en une seule liste. Avec l'un des deux, une
     * seule page est demandée et la réponse est rendue telle quelle
     * (« results », « page », « pages », « total »…).
     *
     * @param string $route
     * @param array $params Paramètres de requête
     *
     * @return array [] si l'API répond 404
     *
     * @throws ApiException
     */
    protected function getAllResultsForRoute($route, array $params = [])
    {
        try {
            if (array_key_exists('page', $params) || array_key_exists('limit', $params)) {
                return $this->getSingleResultForRoute($route, ['query' => $params]);
            }

            $results = [];
            $page = 1;
            do {
                $resultPage = $this->getSingleResultForRoute($route, [
                    'query' => ['limit' => self::PAGE_SIZE, 'page' => $page] + $params,
                ]);

                if (empty($resultPage)) {
                    return [];
                }

                $results = array_merge($results, isset($resultPage['results']) ? $resultPage['results'] : []);
                $page++;
            } while ($page <= (isset($resultPage['pages']) ? $resultPage['pages'] : 0));

            return $results;
        } catch (RequestException $e) {
            // L'API signale une liste vide par un 404.
            if ($e->getResponse() !== null && 404 == $e->getResponse()->getStatusCode()) {
                return [];
            }

            throw $this->toApiException($e);
        }
    }
}
