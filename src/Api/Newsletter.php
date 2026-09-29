<?php

namespace WiziShop\SDK\Api;

/**
 * Abonnés à la newsletter et aux SMS.
 */
trait Newsletter
{
    /**
     * @param array $params email, since_registration (ISO 8601), page, limit
     *
     * @return array NewsletterSubscribers
     */
    public function getNewsletterSubscribers(array $params = [])
    {
        return $this->getAllResultsForRoute('newsletter/subscribers', $params);
    }

    /**
     * @param string $email
     *
     * @return bool
     */
    public function deleteNewsletterSubscriber($email)
    {
        return $this->requestStatus('DELETE', sprintf('newsletter-subscribers/%s', rawurlencode($email)));
    }

    /**
     * @param string $email
     * @param bool $subscribed
     *
     * @return bool
     */
    public function setNewsletterOptin($email, $subscribed)
    {
        return $this->requestStatus('PUT', sprintf('newsletter-subscribers/%s/optin/%d', rawurlencode($email), $subscribed ? 1 : 0));
    }

    /**
     * @param int $customerId
     * @param bool $subscribed
     *
     * @return bool
     */
    public function setSmsOptin($customerId, $subscribed)
    {
        return $this->requestStatus('PUT', sprintf('sms-subscribers/%s/optin/%d', $customerId, $subscribed ? 1 : 0));
    }
}
