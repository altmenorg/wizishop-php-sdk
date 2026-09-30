# SDK PHP de l'API Wizishop v3

Client PHP de l'[API Wizishop v3](https://api-doc.wizishop.com/documentation/3/home).

Fork maintenu du [SDK officiel](https://github.com/WiziShop/wizishop-php-sdk),
qui n'évolue plus. Il reprend des corrections utilisées en production, couvre
l'ensemble des ressources de boutique de la documentation v3 et documente des
comportements de l'API que la documentation ne mentionne pas. Même licence que
l'original : MIT.

## Installation

Dans le `composer.json` du projet :

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/altmenorg/wizishop-php-sdk" }
    ],
    "require": {
        "wizishop/wizishop-php-sdk": "^2.0"
    }
}
```

Le nom du paquet est celui du SDK officiel : `composer update` le remplace
sans changer une ligne du projet.

## Utilisation

```php
use WiziShop\SDK\ApiClientFactory;

$wizishop = ApiClientFactory::authenticate($login, $password, ['shopid' => $shopId]);

$order = $wizishop->getOrder(123456);
$toPrepare = $wizishop->getOrders(['status_code' => 20]);          // toutes les pages
$page = $wizishop->getOrders(['status_code' => 20, 'limit' => 50]); // une page, telle quelle
$wizishop->updateSkuStock('ABC1-stock', 250, 'decrease');
```

Le client est un client Guzzle réglé sur `v3/shops/<shopid>/` : un point
d'accès que le SDK ne couvrirait pas s'appelle par son chemin relatif,
`$wizishop->get('orders/123/invoice')`.

### Jeton

`authenticate()` garde le jeton en cache 24 heures (répertoire temporaire du
système, un fichier par identifiant) : une page ou une tâche cron ne se
reconnecte pas à chaque appel. Un jeton expiré est jeté et renouvelé.
Options : `token_cache => false` pour s'en passer, `token_cache_root` pour
changer de répertoire. `ApiClientFactory::forgetToken($login)` l'oublie.

### Listes, pages et erreurs

| Appel | Résultat |
|---|---|
| une liste sans `page` ni `limit` | toutes les pages, rassemblées en une liste |
| une liste avec `page` ou `limit` | la réponse de l'API : `results`, `page`, `pages`, `total` |
| une liste, réponse 404 | `[]` — c'est ainsi que l'API signale une liste vide |
| une lecture unitaire, réponse 404 | `ApiException` |
| une erreur réseau | `ApiException`, sans réponse (`getResponse()` rend null) |

`ApiException` est une `GuzzleHttp\Exception\RequestException` ;
`getErrorMessage()` rend le champ `message` du corps de la réponse.

## Ressources couvertes

| Ressource | Méthodes |
|---|---|
| Commandes | `getOrders`, `getOrder`, `createOrder`, `getInvoiceForOrder`, `getDeliverySlipForOrder`, `getPickingSlipForOrder`, `setTag`, `getStats` / `getOrderStats` |
| Statuts de commande | `cancelOrder`, `pendingPaymentOrder`, `pendingPaymentVerificationOrder`, `pendingReplenishmentOrder`, `pendingPreparationOrder`, `preparingOrder`, `delayingOrder` / `partiallySentOrder`, `shipOrder`, `deliveredOrder`, `returnOrder`, `returnedOrder`, `refundedOrder`, `customStatusOrder` |
| Statuts personnalisés | `getOrderCustomStates`, `createOrderCustomState`, `updateOrderCustomState`, `deleteOrderCustomState` |
| Clients | `getCustomers`, `getCustomer`, `createCustomer` |
| Newsletter et SMS | `getNewsletterSubscribers`, `deleteNewsletterSubscriber`, `setNewsletterOptin`, `setSmsOptin` |
| SKU et stock | `getSku`, `getSkus`, `getDetailedSkus`, `updateSkuStock` |
| Produits | `getProducts`, `getProduct`, `createProduct`, `updateProduct`, `deleteProduct`, `setProductVisibility`, `getProductFilters`, `updateProductFilters` |
| Catalogue | `getProductCatalog`, `getProductsCatalog`, `getProductSelection`, `getProductSearch` |
| Catégories | `getCategories`, `getCategory`, `getCategoryProducts`, `createCategory`, `updateCategory`, `deleteCategory` |
| Marques | `getBrands`, `getBrand`, `createBrand`, `updateBrand`, `deleteBrand` |
| Avis | `getShopComments`, `createShopComment`, `getProductComments`, `createProductComment` |
| Avoirs | `getStoreCredit` |
| Boutique | `getShop`, `updateShop` |
| Scripts | `getScripts`, `createScript`, `updateScript`, `deleteScript` |
| Webhooks | `getWebhooks`, `getWebhook`, `createWebhook`, `updateWebhook`, `deleteWebhook`, `getWebhookLogs`, `redeliverWebhookEvent` |

Non couverts : les filtres et facettes de produits (hors filtres d'un
produit), les comptes, les utilisateurs et OAuth — hors du périmètre d'une
boutique.

### Constats d'usage, absents de la documentation

- **Création de commande** : `unit_price`, le port et les remises sont TTC
  quand `is_from_tax_excluded` vaut false ; `payment_type` `"2"` donne une
  commande par virement ; une ligne dont le SKU n'existe pas est acceptée, sans
  effet sur aucun stock.
- **Stock** : une commande créée par l'API n'est jamais débitée, quel que soit
  son statut de création, ni au passage de 5 à 20 ; son annulation remet
  pourtant en stock ses vraies déclinaisons. Qui crée des commandes par l'API
  doit donc débiter lui-même, ou utiliser des SKU inexistants.
- **Création de client** : genre 1 = femme, 0 = homme ; le champ de date de
  naissance s'écrit bien `bitrhday_date`.
- **Remise sur commande** (`discounts`, type `advantage`) : montant TTC,
  affichée « Avantage client ».
- **Statistiques** (`order-stats`) : l'API n'accepte les dates que dans l'URL
  écrite à la main, non encodées.

## Vérifier une modification

`tests/regression/` compare deux versions du SDK, sans rien écrire sur la
boutique. Les deux versions déclarent les mêmes classes : chaque harnais tourne
une fois par version, et on compare les sorties.

```bash
# Réponses simulées : pagination, 404, erreurs, encodage, corps envoyés
php tests/regression/mock.php <ancien/src> <projet/vendor/autoload.php> > avant.json
php tests/regression/mock.php <nouveau/src> <projet/vendor/autoload.php> > apres.json
diff avant.json apres.json

# Vraie API, lecture seule — identifiants et données de test en variables
# d'environnement : voir l'en-tête du fichier
php tests/regression/live.php <src> <projet/vendor/autoload.php>

# Cache du jeton, dans les deux sens
php tests/regression/cache.php <src> <projet/vendor/autoload.php> write|read|clean
```

Voir `CHANGELOG.md` pour ce qui a changé et ce qui a été vérifié.
