# CacheBoost Warmer — Magento 2

Déclenche automatiquement un préchauffage de cache CacheBoost dès qu'un flush ou une invalidation se produit dans Magento 2.

- **Mode Smart** : résout les tags Magento (produit, catégorie, page CMS) en URLs et déclenche un warm ciblé via `POST /v1/sites/{id}/warm`.
- **Mode Full Only** : déclenche toujours un Boost planifié complet.
- **Déduplication** : plusieurs events de flush dans la même requête HTTP produisent un seul appel API.
- **Non-bloquant** : timeout de 3 secondes max, les exceptions ne remontent jamais vers Magento.
- **Historique** : les 15 derniers préchauffages inline sont visibles directement dans la config admin.

---

## Prérequis

| Élément | Version |
|---|---|
| Magento | 2.4.x |
| PHP | 8.1+ |
| Extensions PHP | `curl`, `json` |
| Compte CacheBoost | Actif (plan Free ou supérieur) |

---

## Installation

### Option A — Via Composer (recommandé)

```bash
composer require cacheboost/magento2-notifier
bin/magento module:enable CacheBoost_Warmer
bin/magento setup:upgrade
bin/magento cache:flush
```

### Option B — Installation manuelle

1. Créez le dossier `app/code/CacheBoost/Warmer/` à la racine de votre Magento.
2. Copiez tout le contenu du dossier `app/code/CacheBoost/Warmer/` de ce dépôt dedans.
3. Activez le module :

```bash
bin/magento module:enable CacheBoost_Warmer
bin/magento setup:upgrade
bin/magento cache:flush
```

4. Si vous utilisez le mode production :

```bash
bin/magento deploy:mode:set production
```

### Vérification de l'installation

```bash
bin/magento module:status CacheBoost_Warmer
# Doit afficher : Module is enabled
```

---

## Configuration dans l'application CacheBoost

Avant de configurer le plugin Magento, effectuez les étapes suivantes dans [app.cache-boost.com](https://app.cache-boost.com).

### Étape 1 — Récupérer votre Site ID

1. Connectez-vous à [app.cache-boost.com](https://app.cache-boost.com).
2. Allez dans **Sites**.
3. Cliquez sur votre site (ou créez-en un si ce n'est pas encore fait).
4. Le **Site ID** est le numéro affiché dans l'URL ou dans les détails du site (ex. `42`).

> Si votre site n'est pas encore validé, ajoutez le fichier de validation à la racine de votre Magento ou via la méthode DNS, puis cliquez sur **Valider**.

---

### Étape 2 — Créer une clé API

1. Allez dans **API Keys** (menu principal ou paramètres du compte).
2. Cliquez sur **Nouvelle clé**.
3. Donnez-lui un nom (ex. `Magento Production`).
4. Sélectionnez les scopes suivants — **les deux sont obligatoires** :
   - `boosts:read` — pour afficher l'historique dans l'admin Magento
   - `boosts:write` — pour déclencher les préchauffages
5. Restreignez-la à votre site si vous le souhaitez (champ "Sites autorisés").
6. Copiez la clé générée (format `cb_live_…`). Elle n'est affichée qu'une seule fois.

---

### Étape 3 — Créer un Boost avec sitemap (pour le flush total)

> Cette étape est **uniquement nécessaire** pour que les événements de flush total (`Vider tout le cache`, `Vider le stockage`, `Réindexation`) déclenchent un préchauffage. Si vous utilisez uniquement le mode Smart sur les événements granulaires, vous pouvez ignorer cette étape.

Un flush total ne fournit pas de liste d'URLs à Magento. CacheBoost doit donc réchauffer depuis un Boost planifié qui connaît déjà l'ensemble de vos URLs via un sitemap ou un fichier CSV.

1. Dans l'application CacheBoost, allez dans **Boosts** → **Nouveau Boost**.
2. Choisissez le **type Source** :
   - `Sitemap` : entrez l'URL de votre sitemap Magento (ex. `https://mon-site.com/sitemap.xml`). Magento génère un sitemap via **Marketing → SEO & Search → Google Sitemap** — assurez-vous qu'il est configuré et accessible.
   - `CSV` : uploadez ou pointez un fichier CSV de vos URLs.
3. Sélectionnez le **site** créé à l'étape 1.
4. Choisissez la ou les **régions** (doit correspondre à ce que vous configurerez dans Magento).
5. Configurez un **planning** si vous souhaitez aussi des boosts réguliers automatiques (optionnel).
6. Sauvegardez. Notez l'**ID du Boost** (visible dans l'URL ou dans la liste des boosts, ex. `7`).

---

## Configuration dans Magento

Allez dans **Stores → Configuration → CacheBoost → CacheBoost Warmer**.

### Section General

| Champ | Valeur |
|---|---|
| Enable CacheBoost | Oui |
| API Key | Votre clé `cb_live_…` (étape 2) |
| Site ID | ID numérique de votre site (étape 1) |
| Région(s) | Code région CacheBoost, ex. `fr` ou `fr,eu` |
| Mode de préchauffage | `Smart` (recommandé) ou `Full Only` |
| API Endpoint | `https://api.cache-boost.com` (ne pas modifier) |

### Section Flush total — Boost planifié

| Champ | Valeur |
|---|---|
| Boost ID | ID du Boost créé à l'étape 3 (ex. `7`) |

Laissez vide si vous ne souhaitez pas déclencher de boost complet lors des flush totaux.

### Section Historique

L'historique des 15 derniers préchauffages inline apparaît automatiquement une fois l'API Key et le Site ID configurés.

---

## Événements observés

| Événement Magento | Déclencheur | Action |
|---|---|---|
| `adminhtml_cache_flush_all` | Bouton "Vider tout le cache" dans l'admin | Boost run (full) |
| `adminhtml_cache_flush_system` | Bouton "Vider le stockage" | Boost run (full) |
| `clean_cache_after_reindex` | Fin d'une réindexation | Boost run (full) |
| `clean_cache_by_tags` | Save produit, catégorie, page CMS, etc. | Warm ciblé (smart) ou Boost run (full_only) |

Les événements `clean_cache_by_tags` sont **bufferisés** : si 20 produits sont sauvegardés dans la même requête, un seul appel API est émis avec toutes les URLs résolues.

## Résolution des tags en mode Smart

| Tag Magento | Résolu en |
|---|---|
| `cat_p_{id}` | URL(s) produit (toutes les vues de magasin actives) |
| `cat_c_{id}` | URL(s) catégorie |
| `cms_p_{id}` | URL page CMS |
| `cms_b_{id}` | *(ignoré — les blocs CMS n'ont pas d'URL directe)* |

---

## Désinstallation

```bash
bin/magento module:disable CacheBoost_Warmer
bin/magento setup:upgrade
bin/magento cache:flush
# Supprimer le dossier app/code/CacheBoost/ si installation manuelle
```

---

## Dépannage

**L'historique n'apparaît pas dans l'admin.**
Vérifiez que l'API Key a le scope `boosts:read` et que le Site ID est correct. Consultez `var/log/system.log` pour les erreurs `CacheBoost:`.

**Les flush totaux n'ont pas d'effet.**
Assurez-vous qu'un Boost ID est renseigné dans la section "Flush total" et que l'API Key a le scope `boosts:write`. Vérifiez que le Boost n'a pas de run déjà en cours dans l'application CacheBoost.

**Le warm ciblé ne se déclenche pas après un save produit.**
Vérifiez que le mode est bien `Smart`, que le produit a des URLs générées dans la table `url_rewrite` et que la clé API a le scope `boosts:write`.

**Timeout ou lenteur lors du save dans l'admin.**
Le timeout est de 3 secondes. Si l'API CacheBoost est inaccessible depuis votre serveur (firewall, réseau), l'appel bloquera pendant ce délai. Vérifiez la connectivité réseau vers `api.cache-boost.com`.

---

## Licence

MIT
