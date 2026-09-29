<?php

namespace WiziShop\SDK\Api;

/**
 * Avis sur la boutique et sur les produits.
 */
trait Comments
{
    /**
     * @return array
     */
    public function getShopComments()
    {
        return $this->requestJson('GET', 'comments');
    }

    /**
     * @param array $comment author, email, url, content, note (0 à 5), customer,
     *                       valid, select, banned, created_at, ip_address
     *
     * @return array
     */
    public function createShopComment(array $comment)
    {
        return $this->requestJson('POST', 'comments', ['json' => $comment]);
    }

    /**
     * @param int $productId
     *
     * @return array
     */
    public function getProductComments($productId)
    {
        return $this->requestJson('GET', sprintf('products/%s/comments', $productId));
    }

    /**
     * @param int $productId
     * @param array $comment Mêmes champs que createShopComment()
     *
     * @return array
     */
    public function createProductComment($productId, array $comment)
    {
        return $this->requestJson('POST', sprintf('products/%s/comments', $productId), ['json' => $comment]);
    }
}
