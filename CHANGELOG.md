# Journal des changements

## 2.0.0 — 2026-09-29

Refonte d'une version modifiée du SDK officiel, utilisée en production (voir
le commit « Version modifiée utilisée en production »), sans changement de
comportement pour le code qui l'appelle.

### Organisation

- Une ressource par fichier, sous `src/Api/`, en traits : toutes les méthodes
  restent des méthodes de `AuthenticatedApiClient`, avec leurs noms et
  signatures.
- Le cœur (`requestJson`, `requestRaw`, `requestStatus`, pagination,
  conversion des erreurs) n'est plus recopié dans chaque méthode.
- Le cache du jeton sort de `ApiClientFactory.php` dans `TokenCache` ; `Cache`
  reste comme alias. Même répertoire, même nom de fichier, même format : un
  jeton mis en cache par l'ancienne version est relu par la nouvelle, et
  inversement (vérifié).

### Corrigé

- Le `print_r()` de débogage des listes est retiré : à chaque erreur, il
  écrivait l'objet réponse sur la sortie — au milieu d'une réponse JSON, le
  cas échéant.
- Une erreur réseau pendant une lecture de liste provoquait une `Error` PHP
  (`getStatusCode()` sur null), qu'un `catch (Exception)` ne rattrape pas.
  Elle lève désormais `ApiException`, sans réponse.
- `ApiException::getErrorMessage()` ne plante plus sans réponse.
- `shopid` devient facultatif : à défaut, celui du jeton (comportement du SDK
  officiel), au lieu d'un avertissement « undefined index ».
- Un jeton en cache expiré ou illisible est jeté au lieu d'être servi.
- `getCategoryProducts()` transmet enfin ses paramètres.
- Page sans `results` : liste vide, au lieu d'une erreur fatale d'`array_merge`.

### Ajouté, d'après la documentation v3

- Clients : `createCustomer`.
- Statuts de commande personnalisés : `getOrderCustomStates`,
  `createOrderCustomState`, `updateOrderCustomState`, `deleteOrderCustomState`.
- Commandes : `partiallySentOrder` (nom documenté de `delayingOrder`),
  `getOrderStats` (nom documenté de `getStats`).
- Produits : `deleteProduct`, `setProductVisibility`, `getProductFilters`,
  `updateProductFilters`.
- Catégories : `createCategory`, `updateCategory`, `deleteCategory`.
- Newsletter et SMS : `deleteNewsletterSubscriber`, `setNewsletterOptin`,
  `setSmsOptin`.
- Avis : `getShopComments`, `createShopComment`, `getProductComments`,
  `createProductComment`.
- Avoirs : `getStoreCredit`. Boutique : `getShop`, `updateShop`.
- Scripts : `getScripts`, `createScript`, `updateScript`, `deleteScript`.
- Webhooks : `getWebhooks`, `getWebhook`, `createWebhook`, `updateWebhook`,
  `deleteWebhook`, `getWebhookLogs`, `redeliverWebhookEvent`.
- `ApiClientFactory::forgetToken()`, options `token_cache` et
  `token_cache_root`.

### Vérifié

Avec Guzzle 6.5.8 et PHP 8.3 :

- **70 scénarios simulés** (`tests/regression/mock.php`) : adresses, paramètres,
  corps envoyés, résultats et exceptions identiques à la version de
  production, pour toutes les méthodes existantes. Seuls écarts :
  les deux corrections ci-dessus (sortie de débogage, erreur réseau).
- **19 lectures sur la vraie API** (`tests/regression/live.php`) : résultats
  identiques.
- **Cache du jeton** relu dans les deux sens entre l'ancienne et la nouvelle
  version.

Non vérifiées sur la vraie API, faute de pouvoir écrire sans effet : les
méthodes ajoutées. Elles suivent la documentation, qui marque plusieurs de ces
points d'accès [Alpha] ou [Beta].

### Compatibilité

PHP 7.1 ou plus, Guzzle 6. Guzzle 7 n'est pas pris en charge : sa
`BadResponseException` exige une réponse, qu'une erreur réseau n'a pas.
