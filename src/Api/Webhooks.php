<?php

namespace WiziShop\SDK\Api;

/**
 * Webhooks : Wizishop appelle une URL à chaque changement d'une ressource
 * (order, sku, customer), au lieu d'être interrogé.
 */
trait Webhooks
{
    /**
     * @return array
     */
    public function getWebhooks()
    {
        return $this->requestJson('GET', 'webhooks');
    }

    /**
     * @param int $webhookId
     *
     * @return array
     */
    public function getWebhook($webhookId)
    {
        return $this->requestJson('GET', sprintf('webhooks/%s', $webhookId));
    }

    /**
     * @param array $webhook webhook (URL appelée), resources (order, sku, customer),
     *                       description, api_version (v3), num_retries, interval_sec, timeout_sec
     *
     * @return array Le webhook créé
     */
    public function createWebhook(array $webhook)
    {
        return $this->requestJson('POST', 'webhooks', ['json' => $webhook]);
    }

    /**
     * @param int $webhookId
     * @param array $webhook
     *
     * @return array
     */
    public function updateWebhook($webhookId, array $webhook)
    {
        return $this->requestJson('PUT', sprintf('webhooks/%s', $webhookId), ['json' => $webhook]);
    }

    /**
     * @param int $webhookId
     *
     * @return bool
     */
    public function deleteWebhook($webhookId)
    {
        return $this->requestStatus('DELETE', sprintf('webhooks/%s', $webhookId));
    }

    /**
     * @param int $webhookId
     *
     * @return array Les appels passés et leur résultat
     */
    public function getWebhookLogs($webhookId)
    {
        return $this->requestJson('GET', sprintf('webhooks/%s/logs', $webhookId));
    }

    /**
     * Rejoue un appel.
     *
     * @param int $webhookId
     * @param int $eventId
     *
     * @return bool
     */
    public function redeliverWebhookEvent($webhookId, $eventId)
    {
        return $this->requestStatus('POST', sprintf('webhooks/%s/events/%s', $webhookId, $eventId));
    }
}
