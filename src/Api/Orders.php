<?php

namespace WiziShop\SDK\Api;

/**
 * Commandes : lecture, création, documents, étiquette (tag), statistiques.
 * Les changements de statut sont dans OrderStatuses.
 */
trait Orders
{
    /**
     * @param array $params Paramètres de requête :
     *                      status_code : 0 abandonnée, 5 attente de paiement, 10 paiement à vérifier,
     *                                    11 attente de réappro., 20 attente de préparation, 25 en préparation,
     *                                    29 partiellement envoyée, 30 envoyée, 35 livrée, 40 en retour,
     *                                    45 retournée, 46 remboursée, 50 annulée
     *                      customer_id, id_greater_than, query, tag
     *                      start_date, end_date : chaîne ISO 8601, ou DateTime (formaté Y-m-d H:i:s)
     *                      sort : date, -date, id, -id
     *                      page, limit : une seule page, rendue telle quelle
     *
     * @return array Toutes les commandes, ou une page si « page » ou « limit » est donné
     */
    public function getOrders(array $params = [])
    {
        if (array_key_exists('status_code', $params) && ($params['status_code'] < 0 || $params['status_code'] > 50)) {
            throw new \InvalidArgumentException('Order status code should be between 0 and 50');
        }

        foreach (['start_date', 'end_date'] as $field) {
            if (array_key_exists($field, $params) && $params[$field] instanceof \DateTime) {
                $params[$field] = $params[$field]->format('Y-m-d H:i:s');
            }
        }

        return $this->getAllResultsForRoute('orders', $params);
    }

    /**
     * @param int $orderId
     * @param array $params Options de requête Guzzle
     *
     * @return array Commande
     */
    public function getOrder($orderId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('orders/%s', $orderId), $params);
    }

    /**
     * Crée une commande [Alpha dans la documentation Wizishop].
     *
     * Relevé à l'usage : « unit_price », le port et les remises sont TTC quand
     * « is_from_tax_excluded » vaut false ; « payment_type » "2" donne une
     * commande par virement ; une ligne dont le SKU n'existe pas est acceptée,
     * sans effet sur aucun stock.
     *
     * @param array $params
     *
     * @return array La commande créée (id, public_id…)
     */
    public function createOrder(array $params = [])
    {
        return $this->requestJson('POST', 'orders', ['json' => $params]);
    }

    /**
     * @param int $orderId
     * @param array $params Options de requête Guzzle
     *
     * @return string Le PDF
     */
    public function getInvoiceForOrder($orderId, array $params = [])
    {
        return $this->requestRaw(sprintf('orders/%s/invoice', $orderId), $params);
    }

    /**
     * @param int $orderId
     * @param array $params Options de requête Guzzle
     *
     * @return string Le PDF
     */
    public function getPickingSlipForOrder($orderId, array $params = [])
    {
        return $this->requestRaw(sprintf('orders/%s/picking_slip', $orderId), $params);
    }

    /**
     * @param int $orderId
     * @param array $params Options de requête Guzzle
     *
     * @return string Le PDF
     */
    public function getDeliverySlipForOrder($orderId, array $params = [])
    {
        return $this->requestRaw(sprintf('orders/%s/delivery_slip', $orderId), $params);
    }

    /**
     * @param int $orderId
     * @param array $tag Exemple : ['value' => 'mytag']
     *
     * @return array|null L'API répond 204, sans corps
     */
    public function setTag($orderId, array $tag)
    {
        return $this->requestJson('PUT', sprintf('orders/%s/tag', $orderId), ['json' => $tag]);
    }

    /**
     * Statistiques de commandes sur une période.
     *
     * L'URL est écrite à la main, dates non encodées : c'est la seule forme
     * que l'API ait acceptée (paramètres de requête standard refusés).
     *
     * @param array $params from_date, to_date : chaîne, ou DateTime (formaté Y-m-d H:i:s)
     *
     * @return array total_orders, turnover, total_prods_ordered, total_orders_to_prepare
     */
    public function getStats(array $params = [])
    {
        foreach (['from_date', 'to_date'] as $field) {
            if (array_key_exists($field, $params) && $params[$field] instanceof \DateTime) {
                $params[$field] = $params[$field]->format('Y-m-d H:i:s');
            }
        }

        $from = isset($params['from_date']) ? $params['from_date'] : '';
        $to = isset($params['to_date']) ? $params['to_date'] : '';

        return $this->requestJson('GET', 'order-stats?from_date=' . $from . '&to_date=' . $to);
    }

    /**
     * Nom conforme à la documentation (GET order-stats).
     *
     * @param array $params
     *
     * @return array
     */
    public function getOrderStats(array $params = [])
    {
        return $this->getStats($params);
    }
}
