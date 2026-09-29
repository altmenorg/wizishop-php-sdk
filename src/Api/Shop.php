<?php

namespace WiziShop\SDK\Api;

/**
 * La boutique elle-même.
 *
 * Le client est réglé sur « v3/shops/:id/ » : la boutique est donc la racine,
 * une chaîne vide en chemin relatif.
 */
trait Shop
{
    /**
     * @return array
     */
    public function getShop()
    {
        return $this->requestJson('GET', '');
    }

    /**
     * Seul le nom est modifiable pour l'instant, selon la documentation.
     *
     * @param array $shop Exemple : ['name' => '…']
     *
     * @return array|null
     */
    public function updateShop(array $shop)
    {
        return $this->requestJson('PUT', '', ['json' => $shop]);
    }
}
