<?php

namespace WiziShop\SDK;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\RejectedPromise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WiziShop\SDK\Api;
use WiziShop\SDK\Exception\ApiException;
use WiziShop\SDK\Exception\ApiUnavailableException;
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
    const VERSION = '2.2.0';

    /**
     * @const string API URL (ending with /)
     */
    const API_URL = 'https://api.wizishop.com/';

    /**
     * Taille de page utilisée pour rassembler une liste complète.
     */
    const PAGE_SIZE = 100;

    /**
     * Délais par défaut, en secondes : connexion, puis requête entière.
     */
    const CONNECT_TIMEOUT = 5;
    const TIMEOUT = 20;

    /**
     * Relances au plus après un 429 (« Too Many Requests »).
     */
    const RETRY_429 = 3;

    /**
     * Attente maximale demandée par Retry-After, en secondes.
     */
    const RETRY_AFTER_MAX = 30;

    /**
     * @var JWT Json Web Token
     */
    private $jwt;

    /**
     * @var callable|null Rend un JWT neuf (ApiClientFactory : oubli du jeton en cache, nouvelle connexion)
     */
    private $reauthenticate;

    /**
     * @var string|null La dernière requête relancée après reconnexion : un 401
     *                  qui persiste sur la même requête n'est pas un jeton périmé
     */
    private $reauthenticatedRequest;

    /**
     * @var int
     */
    private $maxRetries429;

    /**
     * @var CircuitBreaker|null Donné par ApiClientFactory ; absent pour un client construit à la main
     */
    private $breaker;

    /**
     * @var callable|null function (string $raison, int $tentative, RequestInterface, ResponseInterface)
     */
    private $onRetry;

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

        $this->reauthenticate = isset($config['reauthenticate']) && is_callable($config['reauthenticate']) ? $config['reauthenticate'] : null;
        $this->maxRetries429 = array_key_exists('retry_429', $config) ? max(0, (int) $config['retry_429']) : self::RETRY_429;
        $this->onRetry = isset($config['on_retry']) && is_callable($config['on_retry']) ? $config['on_retry'] : null;
        $this->breaker = isset($config['circuit_breaker']) && $config['circuit_breaker'] instanceof CircuitBreaker ? $config['circuit_breaker'] : null;
        unset($config['reauthenticate'], $config['retry_429'], $config['on_retry'], $config['circuit_breaker']);

        // Sans délai, Guzzle attend indéfiniment : une API qui ne répond plus
        // bloquait chaque page jusqu'au délai du serveur web (20 min), et les
        // workers PHP s'épuisaient. Un appelant peut fixer les siens.
        $config += ['connect_timeout' => self::CONNECT_TIMEOUT, 'timeout' => self::TIMEOUT];

        $handler = isset($config['handler']) && $config['handler'] instanceof HandlerStack ? $config['handler'] : HandlerStack::create();
        // Le coupe-circuit, avant la relance : il voit l'issue finale d'une requête.
        $handler->push(function (callable $next) {
            return function (RequestInterface $request, array $options) use ($next) {
                return $this->guard($next, $request, $options);
            };
        }, 'wizishop_breaker');
        // Poussés après ceux de HandlerStack::create() : ils sont plus près du
        // transport que http_errors, et voient donc la réponse 429 ou 401 avant
        // qu'elle ne devienne une exception. Le jeton est posé à l'intérieur de
        // la relance : une requête relancée après un 401 part avec le nouveau.
        $handler->push(Middleware::retry([$this, 'shouldRetry'], [$this, 'retryDelay']), 'wizishop_retry');
        $handler->push(Middleware::mapRequest(function (RequestInterface $request) {
            return $request->withHeader('Authorization', 'Bearer ' . $this->jwt->getToken());
        }), 'wizishop_auth');
        $config['handler'] = $handler;

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
     * Coupe-circuit (CircuitBreaker) : ouvert, la requête échoue aussitôt ; sinon
     * elle part, et son issue ouvre ou ferme le circuit. Seuls comptent ce qui dit
     * l'API absente — pas de réponse (délai, connexion), 502, 503, 504 — et non un
     * 500 ponctuel d'un point d'accès, ni un 4xx.
     */
    private function guard(callable $next, RequestInterface $request, array $options)
    {
        if ($this->breaker === null) {
            return $next($request, $options);
        }

        $since = $this->breaker->openSince();
        if ($since !== null) {
            return new RejectedPromise(new ApiUnavailableException(
                sprintf("L'API Wizishop ne répond pas depuis %s.", date('H:i', $since)), $request, null, null, $since));
        }

        return $next($request, $options)->then(
            function (ResponseInterface $response) {
                if (in_array($response->getStatusCode(), [502, 503, 504], true)) {
                    $this->breaker->trip();
                } else {
                    $this->breaker->reset();
                }

                return $response;
            },
            function ($reason) {
                if ($reason instanceof RequestException && !$reason->hasResponse()) {
                    $this->breaker->trip();
                }

                return new RejectedPromise($reason);
            }
        );
    }

    /**
     * Relance une requête refusée pour excès de débit (429), en respectant
     * Retry-After, jusqu'à RETRY_429 fois ; et une fois après un 401, si le
     * client sait se reconnecter (ApiClientFactory le lui donne) : le jeton en
     * cache a pu être révoqué avant son échéance.
     *
     * Une requête refusée en 429 ou 401 n'a pas été traitée : la relancer est
     * sans danger, même en POST.
     *
     * @internal appelé par le middleware Guzzle
     */
    public function shouldRetry($retries, RequestInterface $request, ResponseInterface $response = null, $exception = null)
    {
        if ($response === null) {
            return false;
        }

        $status = $response->getStatusCode();

        if ($status === 429 && $retries < $this->maxRetries429) {
            $this->notifyRetry('429', $retries + 1, $request, $response);

            return true;
        }

        if ($status === 401 && $this->reauthenticate !== null && $this->reauthenticatedRequest !== spl_object_hash($request)) {
            $this->reauthenticatedRequest = spl_object_hash($request);
            try {
                $jwt = call_user_func($this->reauthenticate);
            } catch (\Exception $e) {
                return false;
            }
            if (!$jwt instanceof JWT) {
                return false;
            }
            $this->jwt = $jwt;
            $this->notifyRetry('401', $retries + 1, $request, $response);

            return true;
        }

        return false;
    }

    /**
     * Délai avant une relance, en millisecondes : Retry-After s'il est donné
     * (plafonné à RETRY_AFTER_MAX), sinon 1, 2, 4 s ; aucun après un 401.
     *
     * @internal appelé par le middleware Guzzle
     */
    public function retryDelay($retries, ResponseInterface $response = null)
    {
        if ($response !== null && $response->getStatusCode() === 401) {
            return 0;
        }

        if ($response !== null && $response->hasHeader('Retry-After')) {
            $value = trim($response->getHeaderLine('Retry-After'));
            if (ctype_digit($value)) {
                return 1000 * min((int) $value, self::RETRY_AFTER_MAX);
            }
            $date = strtotime($value);
            if ($date !== false) {
                return 1000 * max(0, min($date - time(), self::RETRY_AFTER_MAX));
            }
        }

        return 1000 * (int) pow(2, max(0, $retries - 1));
    }

    private function notifyRetry($reason, $attempt, RequestInterface $request, ResponseInterface $response)
    {
        if ($this->onRetry !== null) {
            try {
                call_user_func($this->onRetry, $reason, $attempt, $request, $response);
            } catch (\Exception $e) {
                // Un journal en échec ne doit pas empêcher la relance.
            }
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
