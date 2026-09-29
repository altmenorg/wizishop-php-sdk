<?php

namespace WiziShop\SDK\Api;

/**
 * Catégories [Beta dans la documentation Wizishop].
 */
trait Categories
{
    /**
     * @param int $catId
     * @param array $params Options de requête Guzzle
     *
     * @return array Category
     */
    public function getCategory($catId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('categories/%s', $catId), $params);
    }

    /**
     * @param array $params page, limit
     *
     * @return array Categories, triées par position
     */
    public function getCategories(array $params = [])
    {
        return $this->getAllResultsForRoute('categories', $params);
    }

    /**
     * @param int $catId
     * @param array $params Options de requête Guzzle — ignorées jusqu'à la
     *                      version 2.0.0, désormais transmises
     *
     * @return array Identifiants des produits de la catégorie
     */
    public function getCategoryProducts($catId, array $params = [])
    {
        return $this->getSingleResultForRoute(sprintf('categories/%s/products', $catId), $params);
    }

    /**
     * @param array $category id_parent (0 pour une racine), name, url, menu_title,
     *                        visible, meta.title, meta.description
     *
     * @return array La catégorie créée
     */
    public function createCategory(array $category)
    {
        return $this->requestJson('POST', 'categories', ['json' => $category]);
    }

    /**
     * @param int $catId
     * @param array $category
     *
     * @return array
     */
    public function updateCategory($catId, array $category)
    {
        return $this->requestJson('PUT', sprintf('categories/%s', $catId), ['json' => $category]);
    }

    /**
     * @param int $catId
     *
     * @return bool
     */
    public function deleteCategory($catId)
    {
        return $this->requestStatus('DELETE', sprintf('categories/%s', $catId));
    }
}
