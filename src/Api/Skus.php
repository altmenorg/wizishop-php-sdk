<?php

namespace WiziShop\SDK\Api;

/**
 * SKU et stock.
 */
trait Skus
{
    /**
     * @param string $sku
     * @param array $params Options de requête Guzzle
     *
     * @return array sku, id, type, ean13, stock, label, purchasing_price, brand_id,
     *               brand_name, weight, created_at, updated_at, status
     */
    public function getSku($sku, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('skus/%s', rawurlencode($sku)), $params);
    }

    /**
     * @param array $params sku, ean13, updated_start, updated_end, detailed,
     *                      with_disabled_variations, sort, page, limit (500 au plus)
     *
     * @return array
     */
    public function getSkus(array $params = [])
    {
        return $this->getAllResultsForRoute('skus', $params);
    }

    /**
     * @param array $params
     *
     * @return array
     */
    public function getDetailedSkus(array $params = [])
    {
        return $this->getAllResultsForRoute('skus', ['detailed' => 1] + $params);
    }

    /**
     * @param string $sku
     * @param int $stock
     * @param string $method replace (défaut), increase ou decrease
     *
     * @return array Sku
     */
    public function updateSkuStock($sku, $stock, $method = 'replace')
    {
        if (!in_array($method, ['replace', 'increase', 'decrease'])) {
            throw new \InvalidArgumentException('Update stock method cannot be ' . $method);
        }

        return $this->requestJson('PUT', sprintf('skus/%s', rawurlencode($sku)), ['json' => [
            'method' => $method,
            'stock'  => $stock,
        ]]);
    }
}
