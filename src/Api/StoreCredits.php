<?php

namespace WiziShop\SDK\Api;

/**
 * Avoirs.
 */
trait StoreCredits
{
    /**
     * @param int $storeCreditId
     *
     * @return array id, id_com, public_id_com, id_client, total_amount,
     *               store_credit_details[]…
     */
    public function getStoreCredit($storeCreditId)
    {
        return $this->requestJson('GET', sprintf('store-credits/%s', $storeCreditId));
    }
}
