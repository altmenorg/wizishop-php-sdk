<?php

namespace WiziShop\SDK;

/**
 * Coupe-circuit partagé entre tous les processus d'une machine (pages web,
 * tâches planifiées) : un fichier dans le répertoire du cache du jeton.
 *
 * Quand l'API ne répond pas (délai dépassé, connexion impossible, 502/503/504),
 * le circuit s'ouvre pour OPEN_SECONDS : pendant ce temps, chaque requête
 * échoue aussitôt, sans attendre le délai — sans quoi chaque page bloquerait un
 * worker PHP jusqu'à son délai, et quelques minutes suffiraient à les prendre
 * tous. Passé ce temps, la requête suivante part : si elle aboutit, le circuit
 * se ferme ; sinon il se rouvre.
 *
 * « Depuis » est l'heure de la première panne d'une série, gardée tant que les
 * échecs se suivent à moins de MEMORY_SECONDS d'intervalle : c'est ce qu'on
 * affiche (« en panne depuis 14 h 32 »).
 */
class CircuitBreaker
{
    const OPEN_SECONDS = 60;
    const MEMORY_SECONDS = 600;
    const KEY = 'wizishop-api-down';

    /**
     * @var TokenCache
     */
    private $cache;

    public function __construct($root = null)
    {
        $this->cache = new TokenCache($root ? ['root' => $root] : []);
    }

    /**
     * @return int|null Depuis quand l'API est en panne, si le circuit est ouvert
     */
    public function openSince()
    {
        $state = $this->cache->get(self::KEY);
        if (!is_array($state) || !isset($state['until'], $state['since'])) {
            return null;
        }

        return $state['until'] > time() ? (int) $state['since'] : null;
    }

    /**
     * Une panne constatée : ouvre (ou rouvre) le circuit.
     */
    public function trip()
    {
        $state = $this->cache->get(self::KEY);
        $since = is_array($state) && isset($state['since']) ? (int) $state['since'] : time();
        $this->cache->set(self::KEY, ['since' => $since, 'until' => time() + self::OPEN_SECONDS], self::MEMORY_SECONDS);
    }

    /**
     * Une réponse reçue : l'API répond, le circuit se ferme.
     */
    public function reset()
    {
        if ($this->cache->get(self::KEY) !== null) {
            $this->cache->remove(self::KEY);
        }
    }
}
