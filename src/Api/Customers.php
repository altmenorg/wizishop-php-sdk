<?php

namespace WiziShop\SDK\Api;

/**
 * Clients.
 */
trait Customers
{
    /**
     * @param int $customerId
     * @param array $params Options de requête Guzzle
     *
     * @return array Client : id, gender, firstname, lastname, email, registered_at, optin,
     *               birthdate, turnover, nb_of_valid_orders, billing_address, delivery_address
     */
    public function getCustomer($customerId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('customers/%s', $customerId), $params);
    }

    /**
     * @param array $params email, since_registration (ISO 8601), page, limit
     *
     * @return array Tous les clients, ou une page si « page » ou « limit » est donné
     */
    public function getCustomers(array $params = [])
    {
        return $this->getAllResultsForRoute('customers', $params);
    }

    /**
     * Crée un client [Alpha dans la documentation Wizishop].
     *
     * Relevé à l'usage : le genre vaut 1 pour une femme, 0 pour un homme ; la
     * réponse (201) est le client complet, identifiant compris. La
     * documentation écrit « bitrhday_date » : c'est le nom attendu.
     *
     * @param array $customer email, password, gender, first_name, last_name, phone,
     *                        bitrhday_date, shipping_address{...}, billing_address{...}
     *                        (address, city, zip, company, country_code, gender,
     *                        first_name, last_name, phone)
     *
     * @return array Le client créé
     */
    public function createCustomer(array $customer)
    {
        return $this->requestJson('POST', 'customers', ['json' => $customer]);
    }
}
