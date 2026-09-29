<?php

namespace WiziShop\SDK;

/**
 * Cache du jeton d'authentification, en fichiers.
 *
 * Sans lui, chaque script qui crée un client appelle /v3/auth/login : une
 * connexion par requête web, et par passage de chaque tâche planifiée.
 *
 * Le format est celui de la classe Cache que la production embarquait dans
 * ApiClientFactory.php : même répertoire (le répertoire temporaire du
 * système), même nom de fichier (md5 de la clé), même contenu (un fichier PHP
 * qui définit $val). Un jeton mis en cache par l'ancienne version est donc
 * relu par celle-ci, et inversement : le déploiement ne force aucune
 * reconnexion.
 */
class TokenCache
{
    /**
     * @var string
     */
    private $root;

    /**
     * @var int|false Durée de vie par défaut, en secondes (false : illimitée)
     */
    private $ttl;

    public function __construct(array $options = [])
    {
        $options = array_merge([
            'root' => sys_get_temp_dir(),
            'ttl'  => false,
        ], $options);

        $this->root = rtrim($options['root'], '/\\');
        $this->ttl = $options['ttl'];
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int|false|null $ttl
     */
    public function set($key, $value, $ttl = null)
    {
        $ttl = $ttl === null ? $this->ttl : $ttl;
        $content = var_export([
            'expiry' => $ttl ? time() + $ttl : false,
            'data'   => $value,
        ], true);

        // Écriture dans un fichier temporaire puis renommage : un lecteur
        // concurrent ne voit jamais un fichier à moitié écrit.
        $destination = $this->path($key);
        $temporary = $destination . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($temporary, '<?php $val = ' . $content . ';', LOCK_EX) === false) {
            return;
        }
        @rename($temporary, $destination);
        $this->invalidate($destination);
    }

    /**
     * @param string $key
     *
     * @return mixed|null null si absent ou expiré
     */
    public function get($key)
    {
        $val = null;
        @include $this->path($key);

        if (!is_array($val) || !array_key_exists('data', $val)) {
            return null;
        }

        if (!$val['expiry'] || $val['expiry'] > time()) {
            return $val['data'];
        }

        $this->remove($key);

        return null;
    }

    /**
     * @param string $key
     */
    public function remove($key)
    {
        $destination = $this->path($key);
        if (@unlink($destination)) {
            $this->invalidate($destination);
        }
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function path($key)
    {
        return $this->root . '/' . md5($key);
    }

    /**
     * Le fichier est un script PHP : OPcache en garderait l'ancienne version.
     *
     * @param string $file
     */
    private function invalidate($file)
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
