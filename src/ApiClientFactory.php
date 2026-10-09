<?php

namespace WiziShop\SDK;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use WiziShop\SDK\Exception\ApiUnavailableException;
use WiziShop\SDK\Exception\AuthenticationException;
use WiziShop\SDK\Model\JWT;

class ApiClientFactory
{
    /**
     * Durée pendant laquelle un jeton est resservi depuis le cache, en secondes.
     * Le jeton lui-même vit 30 jours ; le cache le renouvelle chaque jour.
     */
    const TOKEN_CACHE_TTL = 86400;

    /**
     * @param string $username Username
     * @param string $password Password
     * @param array $config Réglages du client Guzzle, plus :
     *                      - shopid : identifiant de la boutique (sinon, celui du jeton)
     *                      - token_cache : false pour se reconnecter à chaque appel
     *                      - token_cache_root : répertoire du cache (défaut : répertoire temporaire)
     *                      - retry_429 : relances au plus après un 429 (défaut 3, 0 pour aucune)
     *                      - on_retry : function ($raison, $tentative, $requête, $réponse), à chaque relance
     *                      - circuit_breaker : false pour se passer du coupe-circuit (CircuitBreaker)
     *                      - connect_timeout, timeout : délais Guzzle (défaut 5 et 20 s)
     *
     * @return AuthenticatedApiClient
     *
     * @throws AuthenticationException
     */
    public static function authenticate($username, $password, array $config = [])
    {
        $useCache = !array_key_exists('token_cache', $config) || $config['token_cache'] !== false;
        $cacheRoot = isset($config['token_cache_root']) ? $config['token_cache_root'] : null;
        $cache = new TokenCache($cacheRoot ? ['root' => $cacheRoot] : []);
        $breaker = !array_key_exists('circuit_breaker', $config) || $config['circuit_breaker'] !== false
            ? new CircuitBreaker($cacheRoot) : null;
        unset($config['token_cache'], $config['token_cache_root'], $config['circuit_breaker']);

        // Même clé que la version précédente : le jeton déjà en cache est repris.
        $cacheKey = $username . '-token';

        $jwt = null;
        if ($useCache) {
            $cached = $cache->get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                try {
                    $jwt = JWT::fromString($cached);
                } catch (\Exception $e) {
                    $jwt = null;
                }
                // Un jeton expiré ou illisible est jeté, au lieu d'être servi
                // jusqu'à l'échéance du cache.
                if ($jwt === null || $jwt->isExpired()) {
                    $cache->remove($cacheKey);
                    $jwt = null;
                }
            }
        }

        if ($jwt === null) {
            $jwt = self::login($username, $password, isset($config['base_uri']) ? $config['base_uri'] : null, $breaker);
            if ($useCache) {
                $cache->set($cacheKey, $jwt->getToken(), self::TOKEN_CACHE_TTL);
            }
        }

        // Après un 401, le client oublie le jeton en cache et se reconnecte une
        // fois avant de relancer la requête (AuthenticatedApiClient::shouldRetry).
        $apiUrl = isset($config['base_uri']) ? $config['base_uri'] : null;
        $config['reauthenticate'] = function () use ($username, $password, $apiUrl, $useCache, $cache, $cacheKey, $breaker) {
            $cache->remove($cacheKey);
            $jwt = self::login($username, $password, $apiUrl, $breaker);
            if ($useCache) {
                $cache->set($cacheKey, $jwt->getToken(), self::TOKEN_CACHE_TTL);
            }

            return $jwt;
        };

        if ($breaker !== null) {
            $config['circuit_breaker'] = $breaker;
        }

        return new AuthenticatedApiClient($jwt, $config);
    }

    /**
     * Depuis quand l'API est tenue pour en panne (coupe-circuit ouvert), ou
     * null. Aucune requête : de quoi afficher un bandeau sur chaque page.
     *
     * @param string|null $cacheRoot le token_cache_root passé à authenticate(), s'il y en a un
     *
     * @return int|null
     */
    public static function apiDownSince($cacheRoot = null)
    {
        return (new CircuitBreaker($cacheRoot))->openSince();
    }

    /**
     * Oublie le jeton en cache — après un 401, par exemple.
     *
     * @param string $username
     * @param string|null $cacheRoot
     */
    public static function forgetToken($username, $cacheRoot = null)
    {
        $cache = new TokenCache($cacheRoot ? ['root' => $cacheRoot] : []);
        $cache->remove($username . '-token');
    }

    /**
     * @param string $username
     * @param string $password
     * @param string|null $apiUrl
     *
     * @return JWT
     *
     * @throws AuthenticationException
     */
    private static function login($username, $password, $apiUrl = null, CircuitBreaker $breaker = null)
    {
        $client = new Client([
            'base_uri' => $apiUrl ?: AuthenticatedApiClient::API_URL,
            'connect_timeout' => AuthenticatedApiClient::CONNECT_TIMEOUT,
            'timeout' => AuthenticatedApiClient::TIMEOUT,
        ]);

        if ($breaker !== null && ($since = $breaker->openSince()) !== null) {
            throw new ApiUnavailableException(sprintf("L'API Wizishop ne répond pas depuis %s.", date('H:i', $since)),
                new Request('POST', '/v3/auth/login'), null, null, $since);
        }

        try {
            $authResponse = $client->post('/v3/auth/login', [
                'json' => [
                    'username' => $username,
                    'password' => $password,
                ],
            ]);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($breaker !== null && ($response === null || in_array($response->getStatusCode(), [502, 503, 504], true))) {
                $breaker->trip();
                throw new ApiUnavailableException("L'API Wizishop ne répond pas.", $e->getRequest(), $response, $e, time());
            }
            throw new AuthenticationException('Authentication problem', $e->getRequest(), $e->getResponse(), $e);
        }

        $jsonResponse = json_decode((string) $authResponse->getBody(), true);

        return JWT::fromString(isset($jsonResponse['token']) ? $jsonResponse['token'] : '');
    }
}
