<?php

namespace WiziShop\SDK\Api;

/**
 * Statuts de commande personnalisés (ordercustomstate).
 */
trait OrderCustomStates
{
    /**
     * @return array Liste : id_shop, state, title_admin, title_front, created_at, editable
     */
    public function getOrderCustomStates()
    {
        return $this->requestJson('GET', 'ordercustomstate');
    }

    /**
     * @param string $titleAdmin Nom dans le back-office — unique
     * @param string $titleFront Nom affiché sur la boutique
     *
     * @return array Le statut créé, avec son numéro (« state »)
     */
    public function createOrderCustomState($titleAdmin, $titleFront)
    {
        return $this->requestJson('POST', 'ordercustomstate', ['json' => [
            'title_admin' => $titleAdmin,
            'title_front' => $titleFront,
        ]]);
    }

    /**
     * @param int $stateId
     * @param string $titleAdmin
     * @param string $titleFront
     *
     * @return array
     */
    public function updateOrderCustomState($stateId, $titleAdmin, $titleFront)
    {
        return $this->requestJson('PUT', sprintf('ordercustomstate/%s', $stateId), ['json' => [
            'title_admin' => $titleAdmin,
            'title_front' => $titleFront,
        ]]);
    }

    /**
     * Seulement si le statut est « editable ».
     *
     * @param int $stateId
     *
     * @return bool
     */
    public function deleteOrderCustomState($stateId)
    {
        return $this->requestStatus('DELETE', sprintf('ordercustomstate/%s', $stateId));
    }
}
