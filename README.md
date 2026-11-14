# CacheBoost Warmer — Magento 2

Automatically triggers a CacheBoost cache warm-up whenever a flush or invalidation occurs in Magento 2.

- **Smart mode**: resolves Magento tags (product, category, CMS page) to URLs and triggers a targeted warm via `POST /v1/sites/{id}/warm`.
- **Full Only mode**: always triggers a full scheduled Boost run.
- **Deduplication**: multiple flush events within the same HTTP request produce a single queue message.
- **Fully asynchronous**: invalidation events only enqueue a message; tag resolution and API calls run in the background (queue consumer, or the module cron when consumers are not run), adding **zero latency** to customer or admin requests.
- **Works on any hosting**: an Auto processing mode detects whether Magento runs the queue consumer and falls back to a cron job — or to synchronous processing when no cron is available.
- **History**: the 15 most recent warm runs (inline + full) are visible directly in the admin config panel.
- **Connect to CacheBoost**: one button signs you in (or up) and sets up the API key, the site, domain validation, regions and the full-site Boost — no copy-paste.
- **Cache diagnostics**: shares a whitelisted summary of the cache configuration (no host, password or customer data) so CacheBoost can explain cache MISSes.

---

## Requirements

| Item | Version |
|---|---|
| Magento | 2.4.6+ |
| PHP | 8.1+ |
| PHP extensions | `curl`, `json` |
| CacheBoost account | Active (Free plan or higher) |

---

## Multi-store / multi-domain

The module supports a **single CacheBoost Site ID per installation**. In Smart mode, URLs are resolved for all active store views that share the default store view's domain; store views served on a **different domain are skipped**, because their URLs would be rejected by the CacheBoost API (a CacheBoost site is bound to one domain). If you run several domains, create one CacheBoost site per domain and warm the others via their own scheduled Boosts.

---

## Installation

### Option A — Via Composer (recommended)

```bash
composer require cacheboost/module-warmer
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

## Asynchronous warming (message queue)

The module never calls the CacheBoost API during the request that invalidated the cache. Instead, invalidation events are buffered per request and published as a **single message** to the `cacheboost.warm` queue (MySQL-backed by default — no RabbitMQ required). The message is then processed in the background: tags are resolved to URLs and the CacheBoost API is called.

### Processing mode

**Stores → Configuration → CacheBoost → Background Processing → Processing Mode** decides who processes the queue, so the module works on every hosting setup:

| Mode | Who processes the queue | When to use it |
|---|---|---|
| **Auto** (default) | The `cacheboostWarmConsumer` consumer when Magento's consumers runner starts it; otherwise the module's own cron job (`cacheboost_warm_drain_queue`, every minute) | Almost every installation — nothing to configure |
| **Queue consumer only** | `cacheboostWarmConsumer` only, started by Magento cron or by a process supervisor | You run consumers under supervisord/systemd and want the module cron to stay out of the way |
| **Cron job** | The module cron job, every minute | You disabled `cron_consumers_runner` and do not want long-running consumer processes |
| **Synchronous** | The request itself, at shutdown — after the response is sent on PHP-FPM / LiteSpeed | Magento cron does not run at all on this server (last resort) |

How **Auto** decides, from `app/etc/env.php`:

- `cron_consumers_runner.cron_run` is `true` or absent (Magento's default) **and** `cron_consumers_runner.consumers` is empty or lists `cacheboostWarmConsumer` → the consumer is used. The module cron only steps in if messages stay unprocessed for more than 10 minutes (stuck or crashed consumer).
- Otherwise (common on self-hosted servers, where hosts often set `cron_run => false`) → the module cron processes the queue. It leaves a 2-minute head start to a consumer you may run under a supervisor, so the two never fight over messages.

Both paths only need the standard Magento cron (`bin/magento cron:install`), which Magento requires anyway for indexing.

> **Synchronous mode caveat:** on PHP-FPM and LiteSpeed the visitor does not wait (the connection is closed before the API call), but the PHP worker stays busy for the duration of the call (3-second timeout). Under Apache mod_php the response itself is delayed. Prefer a cron-based mode whenever cron is available.

The **Background Processing** panel shows the active strategy, the consumers runner state, pending messages, the last Magento cron run and the last queue processing by the module cron, and warns when Magento cron does not run or when messages are piling up.

### Running the consumer yourself

```bash
bin/magento queue:consumers:start cacheboostWarmConsumer
```

Under supervisord/systemd, pair it with **Queue consumer only** (or keep **Auto**: the module cron never competes with a healthy consumer).

### On Adobe Commerce Cloud

Consumers are managed by the [`CRON_CONSUMERS_RUNNER`](https://experienceleague.adobe.com/en/docs/commerce-on-cloud/user-guide/configure/env/variables-deploy) variable. The default (`cron_run: true`) runs all consumers via cron, including this one. To pin it explicitly in `.magento.env.yaml`:

```yaml
stage:
  deploy:
    CRON_CONSUMERS_RUNNER:
      cron_run: true
      max_messages: 100
      consumers:
        - cacheboostWarmConsumer
```

If your `consumers` list omits `cacheboostWarmConsumer`, **Auto** mode detects it and falls back to the module cron job.

> If your project uses RabbitMQ, the queue can be remapped from the `db` connection to `amqp` via the standard `queue` configuration in `env.php` — no module change needed. Pending-message statistics are only shown for the `db` connection.

---

## Quick start — Connect to CacheBoost

Go to **Stores → Configuration → CacheBoost → CacheBoost Warmer** and click **Connect to CacheBoost**.

1. Sign in, or create a free account (500 URLs per month, no credit card), on the CacheBoost consent page.
2. Click **Authorize**. You come back to the Magento admin, connected.

Everything is then set up automatically, as in the CacheBoost WordPress plugin:

- an **API key restricted to this site** is created and saved (no copy-paste);
- the **site** of your default store view's domain is created in CacheBoost if needed, and its **Site ID** saved;
- the **domain is validated** without uploading a file (see below);
- the **regions** offered by your account are listed, and one is selected;
- a **full-site Boost** is created from your Magento sitemap (**Marketing → SEO & Search → Site Map**, else `/sitemap.xml`) and used for full flushes;
- the **cache configuration** starts being shared for the cache diagnostics (can be turned off).

> CacheBoost sends you back through your **storefront** (`https://{your-domain}/cacheboost/connect/callback/`), which forwards you to the admin: CacheBoost only returns to the connected site's domain, and the Magento admin is often on another host. The one-time code is useless without a secret kept on your server (PKCE), and is never put in an admin URL.

Accounts created this way must **verify their email within 7 days**: the admin shows a reminder before the deadline, and a critical message if warming is paused because it was not.

### Automatic domain validation

CacheBoost rejects warm requests for a domain that is not validated. When you connect, click **Test connection**, or save a new API key, the module validates the domain for you: while CacheBoost checks it, and only then, the storefront answers `https://{your-domain}/{token}.html`. The token is kept in the database (so every web node behind a load balancer can answer) and removed as soon as the check returns. Other storefront requests are not affected: only a path made of a single hexadecimal token is even looked at.

If validation fails (firewall, basic auth, the domain does not point to this server), an admin message says so; click **Test connection** to retry once fixed, or use a manual method at [app.cache-boost.com/sites](https://app.cache-boost.com/sites).

### Manual setup with an API key

You can still paste a key instead of connecting:

1. In [app.cache-boost.com](https://app.cache-boost.com), add your site (the domain of your default store view).
2. In your [profile](https://app.cache-boost.com/profile), create an API key with the scopes `boosts:read`, `boosts:write`, `runs:read` (required), plus `sites:read` (find the Site ID automatically) and `sites:write` (validate the domain and share the cache configuration).
3. Paste it in **API Key** and save. The Site ID, regions and domain validation are filled in on save; an existing Boost of the site is reused for full flushes.

Without `sites:read`, enter the **Site ID** yourself. Without a Boost, create a **sitemap** Boost in CacheBoost and enter its ID under **Full Flush**.

---

## Configuration in Magento

Go to **Stores → Configuration → CacheBoost → CacheBoost Warmer**.

### General section

| Field | Value |
|---|---|
| CacheBoost account | **Connect to CacheBoost** / **Reconnect** |
| Enable CacheBoost | Yes (set automatically by Connect) |
| API Key | Filled in by Connect, or your `cb_live_…` key |
| Site ID | Filled in by Connect, Test connection or saving a key |
| Warm Regions | Regions offered by your account (refreshed by Test connection); none selected = all |
| Warm Mode | `Smart` (recommended) or `Full Only` |

> The API endpoint (`https://api.cache-boost.com`) is built in and requires no configuration.

### Full Flush — Scheduled Boost section

| Field | Value |
|---|---|
| Boost ID | Filled in by Connect (full-site Boost from your sitemap), or the ID of a sitemap/CSV Boost |

Leave empty if you do not want to trigger a full Boost on full flush events.

### Cache Diagnostics section

CacheBoost's cache diagnostics cannot read your server. With **Share the cache configuration with CacheBoost** (on by default once connected), the module sends a summary when it changes, so the diagnostics can explain cache MISSes:

| Sent | Never sent |
|---|---|
| Magento, PHP and module versions, edition, deploy mode, Adobe Commerce Cloud | hosts, IP addresses, ports |
| full page cache: enabled, application (built-in / Varnish / Fastly), TTL, grace period, number of Varnish purge hosts | passwords, keys, tokens |
| enabled / invalidated cache types | customer or order data |
| kind of cache and session backend (file / Redis / Valkey / database…) | URLs other than counts of domains |
| number of websites, store views, domains, currencies; store code in URLs | |
| cache-related modules installed (Fastly_Cdn, Amasty_Fpc, Mirasvit_CacheWarmer, Litespeed_Litemage) | |
| CacheBoost settings (warm mode, full warming, processing mode) | |

The snapshot is checked every 5 minutes after a configuration save and hourly otherwise, and sent only when it changed (`cacheboost_environment_snapshot` cron job, endpoint `POST /v1/sites/{id}/environment`, scope `sites:write`). **Send now** resends it at once. Turning sharing off sends one `DELETE`, so CacheBoost deletes what it received.

#### What the cache diagnostics check on Magento

With the snapshot, CacheBoost's cache diagnostics (app.cache-boost.com › your site › Cache configuration) add a **Magento (origin)** section:

| Criterion | Weight | Fails or warns when |
|---|---|---|
| Page cache is on | critical | the `full_page` cache type or the full page cache is off |
| Production mode | major | Magento runs in developer or default mode (maintenance warns) |
| Varnish or Fastly | major | the built-in page cache serves HITs from PHP (warning) |
| Magento can purge Varnish | major | Varnish is used but no `http_cache_hosts` is declared: changed pages stay stale and warms reheat them |
| Cache types | major | a cache type is disabled (fail) or invalidated (warning) |
| Page cache lifetime vs boost interval | major | the public content cache lifetime is shorter than the interval between two scheduled boosts |
| Cache storage | minor | the default cache (or the built-in page cache) is stored in files or the database instead of Redis/Valkey |
| Single warmer | minor | Amasty Full Page Cache Warmer or Mirasvit Cache Warmer is enabled too |
| Currencies | info | several currencies: each one is a cache variant, only the default one is warmed |
| Module up to date | info | a newer CacheBoost module is available |

Each failed criterion comes with the Magento admin path or `bin/magento` command that fixes it. On a site that is not Magento these criteria do not apply; on a Magento site without the module they are reported as "source missing".

### Tests & Diagnostic section

Three buttons let you validate your configuration without leaving the admin:

- **Test connection** — checks the API key and its scopes, finds the site of your storefront, refreshes the regions and validates the domain if needed.
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

`clean_cache_by_tags` events are **buffered**: if 20 products are saved in the same request, a single queue message is published, and the consumer makes a single API call with all resolved URLs. If more than 500 tags are invalidated at once (e.g. a large partial reindex), the consumer falls back to the full scheduled Boost instead of resolving them individually.

## Tag resolution in Smart mode

| Magento tag | Resolved to |
|---|---|
| `cat_p_{id}` | Product URL(s) (all active store views) |
| `cat_c_{id}` | Category URL(s) |
| `cms_p_{id}` | CMS page URL |
| `cms_b_{id}` | *(ignored — CMS blocks have no direct URL)* |

> **Multi-store note:** URLs are resolved for all active store views sharing the default store view's domain. Store views served on a different domain are skipped, because a CacheBoost site is bound to a single domain (create one CacheBoost site per domain if needed).

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

> In production, use `composer install --no-dev` to skip test dependencies.

Tests cover `Config`, `ApiClient`, `AccountApi`, `UrlCollector`, `TagUrlResolver`, the `WarmConsumer` queue consumer, the processing strategy (`ProcessingStrategy`, `QueueInspector`, `QueueDrainer`, `ProcessingDiagnostics`, the `DrainWarmQueue` cron job), Connect (`ConnectFlow`, `AccountSetup`, `StoreDomain`), domain validation (`SiteValidation`), the account email state (`AccountStatus`) and the cache configuration snapshot (`SnapshotCollector`, `EnvironmentReporter`). All Magento framework dependencies are replaced by lightweight stubs in `tests/stubs.php`, so the suite runs anywhere PHP 8.1+ and Composer are available.

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

**"The connection link expired" after Connect to CacheBoost.**
The flow must be completed within 15 minutes, in the same admin session, by the admin who started it. Click **Connect to CacheBoost** again. If CacheBoost cannot send you back, check that `https://{your-domain}/cacheboost/connect/callback/` reaches Magento (not blocked by basic auth or a firewall).

**"Your domain is not validated in CacheBoost".**
CacheBoost must reach `https://{your-domain}/{token}.html` on this server during validation. Remove any basic auth or IP restriction for that request, make sure the domain of the default store view points to this server, then click **Test connection**. The key needs the `sites:write` scope (keys created by Connect have it).

**History does not appear in the admin.**
Check that the API key has the `boosts:read` scope and that the Site ID is correct. Check `var/log/system.log` for `CacheBoost:` errors.

**Full flush events have no effect.**
Make sure a Boost ID is set in the "Full Flush" section and that the API key has the `boosts:write` scope. Verify that the Boost does not already have a run in progress in the CacheBoost application.

**Targeted warm does not trigger after saving a product.**
Check that the mode is set to `Smart`, that the product has URLs generated in the `url_rewrite` table, and that the API key has the `boosts:write` scope.

**Nothing is warmed even though messages are queued.**
Open **Stores → Configuration → CacheBoost → Background Processing**: the status panel shows which strategy is active and flags the usual causes. Check that Magento cron runs (`bin/magento cron:install`, then look at the `cron_schedule` table); if it cannot run on this server, switch **Processing Mode** to **Synchronous**. With **Queue consumer only**, make sure `cacheboostWarmConsumer` is started by the consumers runner or by your supervisor. Pending messages are visible in the `queue_message_status` table.

**Warm requests fail once consumed.**
The API timeout is 3 seconds per call, performed by the background consumer (never by customer requests). If the CacheBoost API is unreachable from your server (firewall, network), check connectivity to `api.cache-boost.com` and look for `CacheBoost:` errors in `var/log/system.log`.

---

## License

MIT
