<?php

namespace WiziShop\SDK;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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
     *
     * @return AuthenticatedApiClient
     *
     * @throws AuthenticationException
     */
    public static function authenticate($username, $password, array $config = [])
    {
        $useCache = !array_key_exists('token_cache', $config) || $config['token_cache'] !== false;
        $cache = new TokenCache(isset($config['token_cache_root']) ? ['root' => $config['token_cache_root']] : []);
        unset($config['token_cache'], $config['token_cache_root']);

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
            $jwt = self::login($username, $password, isset($config['base_uri']) ? $config['base_uri'] : null);
            if ($useCache) {
                $cache->set($cacheKey, $jwt->getToken(), self::TOKEN_CACHE_TTL);
            }
        }

        return new AuthenticatedApiClient($jwt, $config);
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
    private static function login($username, $password, $apiUrl = null)
    {
        $client = new Client([
            'base_uri' => $apiUrl ?: AuthenticatedApiClient::API_URL,
        ]);

        try {
            $authResponse = $client->post('/v3/auth/login', [
                'json' => [
                    'username' => $username,
                    'password' => $password,
                ],
            ]);
        } catch (RequestException $e) {
            throw new AuthenticationException('Authentication problem', $e->getRequest(), $e->getResponse(), $e);
        }

        $jsonResponse = json_decode((string) $authResponse->getBody(), true);

        return JWT::fromString(isset($jsonResponse['token']) ? $jsonResponse['token'] : '');
    }
}
