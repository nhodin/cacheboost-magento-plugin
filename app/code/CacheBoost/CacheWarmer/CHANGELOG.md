# Changelog

## 1.1.0
- Asynchronous warming through the Magento message queue, with support for hosts that do not run the queue consumer.
- Feature parity with the WordPress plugin: connect flow, environment diagnostics, processing status.
- Admin panels rendered through CSP-safe templates.
- Module renamed to `CacheBoost_CacheWarmer`, Composer package `cacheboost/cache-warmer`.

## 1.0.0
- Initial release: targeted (Smart) and full (Boost run) cache warming on Magento flush and invalidation events.
- Admin configuration panel with connection, inline warm and Boost run tests, and warm history.
- Translations: en_US, fr_FR, de_DE, es_ES, nl_NL.
