<?php
/**
 * Charge une version donnée du SDK devant l'autoloader d'un projet qui fournit
 * Guzzle :
 *
 *   require 'bootstrap.php';
 *   sdk_bootstrap('/chemin/vers/le/sdk/src', '/chemin/vers/projet/vendor/autoload.php');
 *
 * Les deux versions déclarent les mêmes classes : on ne peut pas les charger
 * dans le même processus. Chaque harnais tourne donc une fois par version, et
 * les sorties se comparent ensuite.
 */

function sdk_bootstrap($sdkSrc, $projectAutoload)
{
    $sdkSrc = rtrim($sdkSrc, '/');

    // Composer s'inscrit en tête de file : il est chargé d'abord, pour que
    // notre chargeur passe ensuite devant lui.
    require $projectAutoload;

    // L'ancienne version déclarait sa classe Cache dans ApiClientFactory.php :
    // un chargement fichier par classe ne la trouverait pas. On charge donc
    // explicitement la fabrique, le reste suit l'autoloader.
    spl_autoload_register(function ($class) use ($sdkSrc) {
        $prefix = 'WiziShop\\SDK\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $file = $sdkSrc . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }, true, true);
}

/**
 * Représentation stable d'un résultat ou d'une exception, pour comparaison.
 */
function sdk_describe($value)
{
    if ($value instanceof \Throwable) {
        $out = ['exception' => get_class($value), 'message' => $value->getMessage()];
        if (method_exists($value, 'getResponse')) {
            $response = $value->getResponse();
            $out['status'] = $response ? $response->getStatusCode() : null;
        }
        return $out;
    }

    return ['result' => $value];
}
