<?php
/**
 * Compatibilité du cache de jeton entre versions : ce qu'une version écrit,
 * l'autre le relit. Clé factice, fichier supprimé à la fin.
 *
 *   php cache.php <sdk/src> <projet/vendor/autoload.php> write|read|clean
 */

require __DIR__ . '/bootstrap.php';
sdk_bootstrap($argv[1], $argv[2]);

$key = 'wizishop-sdk-regression-token';
// L'ancienne version déclarait Cache dans ApiClientFactory.php.
class_exists('WiziShop\SDK\ApiClientFactory');
$cache = new \WiziShop\SDK\Cache();

switch ($argv[3]) {
    case 'write':
        $cache->set($key, 'jeton-' . basename(dirname($argv[1])), 60);
        echo "écrit\n";
        break;
    case 'read':
        var_export($cache->get($key));
        echo "\n";
        break;
    case 'clean':
        $cache->remove($key);
        echo "supprimé\n";
        break;
}
