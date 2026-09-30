<?php

namespace WiziShop\SDK\Api;

/**
 * Changements de statut d'une commande (PUT orders/:id/status/...).
 *
 * Chaque méthode rend la réponse de l'API décodée — la commande, en général.
 */
trait OrderStatuses
{
    /**
     * @param int $orderId
     * @param string $status Segment d'URL : cancel, preparing, delivered…
     * @param array $options Options de requête Guzzle
     *
     * @return array|null
     */
    protected function changeOrderStatus($orderId, $status, array $options = [])
    {
        return $this->requestJson('PUT', sprintf('orders/%s/status/%s', $orderId, $status), $options);
    }

    /**
     * Annulée (50).
     *
     * Wizishop remet alors en stock les déclinaisons réelles de la commande —
     * y compris celles qu'il n'a jamais débitées : une commande créée par
     * l'API ne l'est pas, quel que soit son statut, ni au passage de 5 à 20.
     */
    public function cancelOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'cancel');
    }

    /** En attente de paiement (5). */
    public function pendingPaymentOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'pending_payment');
    }

    /** Paiement en attente de vérification (10). */
    public function pendingPaymentVerificationOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'pending_payment_verification');
    }

    /** En attente de réapprovisionnement (11). */
    public function pendingReplenishmentOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'pending_replenishment');
    }

    /** En attente de préparation (20). */
    public function pendingPreparationOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'pending_preparation');
    }

    /** En préparation (25). */
    public function preparingOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'preparing');
    }

    /**
     * Partiellement envoyée (29). Nom historique, conservé ;
     * partiallySentOrder() est le nom documenté.
     */
    public function delayingOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'partially_sent');
    }

    /** Nom conforme à la documentation : partiellement envoyée (29). */
    public function partiallySentOrder($orderId)
    {
        return $this->delayingOrder($orderId);
    }

    /**
     * Envoyée (30).
     *
     * @param int $orderId
     * @param array $trackingNumbers Exemple :
     *                               ['tracking_numbers' => [
     *                                   ['shipping_id' => 39, 'tracking_number' => 'XVBFD-2', 'tracking_url' => '…']
     *                               ]]
     *                               « tracking_url » est facultatif.
     */
    public function shipOrder($orderId, array $trackingNumbers)
    {
        return $this->changeOrderStatus($orderId, 'ship', ['json' => $trackingNumbers]);
    }

    /** Livrée (35). */
    public function deliveredOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'delivered');
    }

    /** En cours de retour (40). */
    public function returnOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'return');
    }

    /** Retournée (45). */
    public function returnedOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'returned');
    }

    /** Remboursée (46). */
    public function refundedOrder($orderId)
    {
        return $this->changeOrderStatus($orderId, 'refunded');
    }

    /**
     * Statut personnalisé (voir getOrderCustomStates()).
     *
     * @param int $orderId
     * @param int $statusId
     */
    public function customStatusOrder($orderId, $statusId)
    {
        return $this->requestJson('PUT', sprintf('orders/%s/custom_status/%s', $orderId, $statusId));
    }
}
