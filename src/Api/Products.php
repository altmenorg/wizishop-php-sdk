<?php

namespace WiziShop\SDK\Api;

/**
 * Produits, catalogue, filtres de produits.
 */
trait Products
{
    /**
     * @param array $params status, sku, sort, page, limit
     *
     * @return array
     */
    public function getProducts(array $params = [])
    {
        return $this->getAllResultsForRoute('products', $params);
    }

    /**
     * @param int $productId
     * @param array $params Options de requête Guzzle
     *
     * @return array
     */
    public function getProduct($productId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('products/%s', $productId), $params);
    }

    /**
     * @param array $params
     *
     * @return array Le produit créé
     */
    public function createProduct(array $params = [])
    {
        return $this->requestJson('POST', 'products', ['json' => $params]);
    }

    /**
     * @param int $productId
     * @param array $params
     *
     * @return array
     */
    public function updateProduct($productId, array $params = [])
    {
        return $this->requestJson('PUT', sprintf('products/%s', $productId), ['json' => $params]);
    }

    /**
     * @param int $productId
     *
     * @return bool
     */
    public function deleteProduct($productId)
    {
        return $this->requestStatus('DELETE', sprintf('products/%s', $productId));
    }

    /**
     * @param int $productId
     * @param string $status hidden, visible ou unavailable
     *
     * @return array|null
     */
    public function setProductVisibility($productId, $status)
    {
        if (!in_array($status, ['hidden', 'visible', 'unavailable'], true)) {
            throw new \InvalidArgumentException('Product visibility cannot be ' . $status);
        }

        return $this->requestJson('PUT', sprintf('products/%s/visibility/%s', $productId, $status));
    }

    /**
     * @param int $productId
     *
     * @return array
     */
    public function getProductFilters($productId)
    {
        return $this->requestJson('GET', sprintf('products/%s/filters', $productId));
    }

    /**
     * Remplace les filtres du produit.
     *
     * @param int $productId
     * @param array $productFilters
     *
     * @return array
     */
    public function updateProductFilters($productId, array $productFilters)
    {
        return $this->requestJson('PUT', sprintf('products/%s/filters', $productId), ['json' => [
            'productFilters' => $productFilters,
        ]]);
    }

    /**
     * Points d'accès « catalog-* » : non documentés dans l'API v3 publique,
     * mais servis — ce sont eux qui rendent les déclinaisons et leurs prix.
     *
     * @param array $params type (flash, discount, new), page, limit
     *
     * @return array
     */
    public function getProductSelection(array $params = [])
    {
        return $this->getAllResultsForRoute('catalog-selection', $params);
    }

    /**
     * @param int $productId
     *
     * @return array Une page : ['products' => [produit], ...]
     */
    public function getProductCatalog($productId)
    {
        return $this->getAllResultsForRoute('catalog-specific', [
            'prodIds' => json_encode([$productId]),
            'page'    => 1,
            'limit'   => 1,
        ]);
    }

    /**
     * @param array $productIds 200 au plus
     *
     * @return array Une page : ['products' => [...], ...]
     */
    public function getProductsCatalog($productIds)
    {
        return $this->getAllResultsForRoute('catalog-specific', [
            'prodIds' => json_encode($productIds),
            'page'    => 1,
            'limit'   => 200,
        ]);
    }

    /**
     * @param array $params search, page, limit
     *
     * @return array
     */
    public function getProductSearch(array $params = [])
    {
        return $this->getAllResultsForRoute('catalog-search', $params);
    }
}
