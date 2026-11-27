# Changelog

## [1.0.0] - 2026-11-27

### Added
- Automatic Laravel package discovery, HTTP exception capture and failed-job monitoring.
- Automatic feedback widget and browser monitoring injection into HTML responses.
- Public/server credential separation, sanitization and exception deduplication.
- Best-effort HTTP delivery without a worker, and optional queued delivery with retries.
- PHP, Laravel and installed Composer SDK version in runtime diagnostics.
- Application release tracking independently through `PULSE_RELEASE`.
- Compatibility tests for PHP 8.1/Laravel 10, PHP 8.2/Laravel 10-12,
  and PHP 8.3-8.4/Laravel 13.
- Legacy environment variables and PHP class aliases for existing integrations/jobs.

### Compatibility
- PHP 8.1+ and Laravel 10-13, subject to the framework's own PHP requirements.
- Existing server runtime schema is retained; SDK name/version fit in its `sdk` field.
- PHP 8.1 and Laravel 10/11 support is for legacy applications, not a security endorsement.

### Limitations
- Best-effort HTTP mode has no persistent retry queue and occupies the PHP worker during delivery.
- Automatic widget injection applies to HTML served by Laravel, not independently hosted static pages.
- Restrictive CSP requires the Pulse asset/API origins to be allowed by the host application.
- The separately hosted browser/widget assets are supplied by the configured Pulse server.

[1.0.0]: https://github.com/aderitodebarros/pulse-laravel/releases/tag/v1.0.0
