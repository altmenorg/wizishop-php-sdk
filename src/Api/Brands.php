<?php

namespace WiziShop\SDK\Api;

/**
 * Marques.
 */
trait Brands
{
    /**
     * @param int $brandId
     * @param array $params Options de requête Guzzle
     *
     * @return array Brand
     */
    public function getBrand($brandId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('brands/%s', $brandId), $params);
    }

    /**
     * @param array $params page, limit
     *
     * @return array Brands
     */
    public function getBrands(array $params = [])
    {
        return $this->getAllResultsForRoute('brands', $params);
    }

    /**
     * @param string $name
     * @param string|null $newImageUrl
     *
     * @return array Brand
     */
    public function createBrand($name, $newImageUrl = null)
    {
        $fields = ['name' => $name];
        if ($newImageUrl) {
            $fields['image_url'] = $newImageUrl;
        }

        return $this->requestJson('POST', 'brands', ['json' => $fields]);
    }

    /**
     * @param int $brandId
     * @param string $newName
     * @param string|null $newUrl
     * @param string|null $newImageUrl
     *
     * @return array Brand
     */
    public function updateBrand($brandId, $newName, $newUrl = null, $newImageUrl = null)
    {
        $fields = ['name' => $newName];
        if ($newUrl) {
            $fields['url'] = $newUrl;
        }
        if ($newImageUrl) {
            $fields['image_url'] = $newImageUrl;
        }

        return $this->requestJson('PUT', sprintf('brands/%s', $brandId), ['json' => $fields]);
    }

    /**
     * @param int $brandId
     *
     * @return bool true si l'API a répondu 204
     */
    public function deleteBrand($brandId)
    {
        try {
            return 204 == $this->request('DELETE', sprintf('brands/%s', $brandId))->getStatusCode();
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            throw $this->toApiException($e);
        }
    }
}
