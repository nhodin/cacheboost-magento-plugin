# CacheBoost Warmer — Magento 2

Automatically triggers a CacheBoost cache warm-up whenever a flush or invalidation occurs in Magento 2.

- **Smart mode**: resolves Magento tags (product, category, CMS page) to URLs and triggers a targeted warm via `POST /v1/sites/{id}/warm`.
- **Full Only mode**: always triggers a full scheduled Boost run.
- **Deduplication**: multiple flush events within the same HTTP request produce a single API call.
- **Non-blocking**: 3-second timeout max; exceptions never bubble up to Magento.
- **History**: the 15 most recent warm runs (inline + full) are visible directly in the admin config panel.

---

## Requirements

| Item | Version |
|---|---|
| Magento | 2.4.x |
| PHP | 8.1+ |
| PHP extensions | `curl`, `json` |
| CacheBoost account | Active (Free plan or higher) |

---

## Installation

### Option A — Via Composer (recommended)

```bash
composer require cacheboost/magento2-notifier
bin/magento module:enable CacheBoost_Warmer
bin/magento setup:upgrade
bin/magento cache:flush
```

### Option B — Manual installation

1. Create the directory `app/code/CacheBoost/Warmer/` at the root of your Magento installation.
2. Copy the full contents of `app/code/CacheBoost/Warmer/` from this repository into it.
3. Enable the module:

```bash
bin/magento module:enable CacheBoost_Warmer
bin/magento setup:upgrade
bin/magento cache:flush
```

4. If you are using production mode:

```bash
bin/magento deploy:mode:set production
```

### Verify installation

```bash
bin/magento module:status CacheBoost_Warmer
# Should output: Module is enabled
```

---

## Setup in the CacheBoost application

Before configuring the Magento plugin, complete the following steps in [app.cache-boost.com](https://app.cache-boost.com).

### Step 1 — Retrieve your Site ID

1. Log in to [app.cache-boost.com](https://app.cache-boost.com).
2. Go to **Sites**.
3. Click on your site (or create one if you haven't yet).
4. The **Site ID** is the number shown in the URL or in the site details (e.g. `42`).

> If your site is not yet validated, add the validation file to the root of your Magento installation or use the DNS method, then click **Validate**.

---

### Step 2 — Create an API key

1. Go to **API Keys** (main menu or account settings).
2. Click **New key**.
3. Give it a name (e.g. `Magento Production`).
4. Select the following scopes — **all three are required**:
   - `boosts:read` — to display warm history in the Magento admin
   - `boosts:write` — to trigger warm-ups
   - `runs:read` — to display scheduled Boost run history
5. Optionally restrict it to your site (the "Authorized sites" field).
6. Copy the generated key (format `cb_live_…`). It is shown only once.

---

### Step 3 — Create a Boost with a sitemap (for full flush)

> This step is **only required** for full flush events (`Flush All Cache`, `Flush Storage`, `Reindex`) to trigger a warm-up. If you only use Smart mode on granular events, you can skip this step.

A full flush provides no URL list to Magento. CacheBoost therefore needs to warm from a scheduled Boost that already knows all your URLs via a sitemap or CSV file.

1. In the CacheBoost application, go to **Boosts** → **New Boost**.
2. Choose the **Source type**:
   - `Sitemap`: enter your Magento sitemap URL (e.g. `https://my-store.com/sitemap.xml`). Magento generates a sitemap via **Marketing → SEO & Search → Google Sitemap** — make sure it is configured and accessible.
   - `CSV`: upload or point to a CSV file of your URLs.
3. Select the **site** created in Step 1.
4. Choose the **region(s)** (must match what you will configure in Magento).
5. Optionally configure a **schedule** for automatic recurring boosts.
6. Save. Note the **Boost ID** (visible in the URL or in the Boost list, e.g. `7`).

---

## Configuration in Magento

Go to **Stores → Configuration → CacheBoost → CacheBoost Warmer**.

### General section

| Field | Value |
|---|---|
| Enable CacheBoost | Yes |
| API Key | Your `cb_live_…` key (Step 2) |
| Site ID | Numeric ID of your site (Step 1) |
| Region(s) | CacheBoost region code(s), e.g. `fr` or `fr,eu` |
| Warm Mode | `Smart` (recommended) or `Full Only` |
| API Endpoint | `https://api.cache-boost.com` (do not change) |

### Full Flush — Scheduled Boost section

| Field | Value |
|---|---|
| Boost ID | ID of the Boost created in Step 3 (e.g. `7`) |

Leave empty if you do not want to trigger a full Boost on full flush events.

### Tests & Diagnostic section

Three buttons let you validate your configuration without leaving the admin:

- **Test connection** — verifies that the API key and Site ID are valid.
- **Test inline warm** — triggers a warm on the store base URL.
- **Test Boost run** — triggers a run on the configured Boost ID.

### History section

The 15 most recent warm runs (inline and full) appear automatically once the API Key and Site ID are configured. Each run links to the CacheBoost application for full details.

---

## Observed events

| Magento event | Trigger | Action |
|---|---|---|
| `adminhtml_cache_flush_all` | "Flush All Cache" button in admin | Full Boost run |
| `adminhtml_cache_flush_system` | "Flush Storage" button | Full Boost run |
| `clean_cache_after_reindex` | End of a reindex | Full Boost run |
| `clean_cache_by_tags` | Product/category/CMS page save, etc. | Targeted warm (Smart) or full Boost run (Full Only) |

`clean_cache_by_tags` events are **buffered**: if 20 products are saved in the same request, a single API call is made with all resolved URLs.

## Tag resolution in Smart mode

| Magento tag | Resolved to |
|---|---|
| `cat_p_{id}` | Product URL(s) (all active store views) |
| `cat_c_{id}` | Category URL(s) |
| `cms_p_{id}` | CMS page URL |
| `cms_b_{id}` | *(ignored — CMS blocks have no direct URL)* |

---

## Translations

The admin interface is fully translated into the following languages:

| Locale | Language |
|---|---|
| `en_US` | English (default) |
| `fr_FR` | French |
| `de_DE` | German |
| `es_ES` | Spanish |
| `nl_NL` | Dutch |

Magento automatically loads the correct translation based on your store's locale. To add a new language, copy `i18n/en_US.csv` to `i18n/{locale}.csv` and translate the second column.

---

## Running the unit tests

The plugin ships with a standalone PHPUnit test suite that requires no Magento installation.

```bash
cd magento-plugin
composer install
vendor/bin/phpunit
```

Tests cover `Config`, `ApiClient`, and `UrlCollector`. All Magento framework dependencies are replaced by lightweight stubs in `tests/stubs.php`, so the suite runs anywhere PHP 8.1+ and Composer are available.

---

## Uninstallation

```bash
bin/magento module:disable CacheBoost_Warmer
bin/magento setup:upgrade
bin/magento cache:flush
# Remove the app/code/CacheBoost/ directory if you installed manually
```

---

## Troubleshooting

**History does not appear in the admin.**
Check that the API key has the `boosts:read` scope and that the Site ID is correct. Check `var/log/system.log` for `CacheBoost:` errors.

**Full flush events have no effect.**
Make sure a Boost ID is set in the "Full Flush" section and that the API key has the `boosts:write` scope. Verify that the Boost does not already have a run in progress in the CacheBoost application.

**Targeted warm does not trigger after saving a product.**
Check that the mode is set to `Smart`, that the product has URLs generated in the `url_rewrite` table, and that the API key has the `boosts:write` scope.

**Timeout or slowness when saving in the admin.**
The timeout is 3 seconds. If the CacheBoost API is unreachable from your server (firewall, network), the call will block for that duration. Check network connectivity to `api.cache-boost.com`.

---

## License

MIT
