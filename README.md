# bifrost/pulse-laravel

Laravel 11-13 / PHP 8.3+. After publication:

```sh
composer require bifrost/pulse-laravel
```

Before publication use a Composer path repository pointing to this directory
and require `bifrost/pulse-laravel:@dev`. Configure only the application's `.env`:

```dotenv
PULSE_URL=https://pulse.bifrost.co.mz
PULSE_KEY=ps_sk_YOUR_SERVER_KEY
PULSE_PUBLIC_KEY=ps_pk_live_YOUR_PUBLIC_KEY
PULSE_ENVIRONMENT=production
```

Both keys must belong to the same Pulse project. The secret needs `errors:create`;
the public key must allow the application's origin. The environment must exist in
the project. Only the public key reaches HTML. Without a public key, the widget and
browser monitoring remain off; without a secret key, backend reporting stays off.

Package discovery registers everything automatically. HTML responses receive the
widget and browser monitoring without editing layouts, bootstrap or root files.
No npm build, database migration, worker or vendor:publish step is required.
JSON, downloads, streams and Livewire partial responses stay unchanged.
The SDK does not create keys, change origin permissions or overwrite root files.

HTTP reporting defaults to best-effort delivery with a two-second timeout and no
durable retries. For web requests it runs in an application termination callback;
PHP-FPM can send the response first, but its worker stays occupied during delivery.
Other runtimes may still expose that delay to callers. CLI/job captures send directly.

To opt into durable queued delivery, configure `PULSE_TRANSPORT=queue`,
`PULSE_QUEUE_CONNECTION=database` and optionally `PULSE_QUEUE=default`. This mode
requires queue tables/backend and `php artisan queue:work database --tries=3 --timeout=30`.
Sync queues remain disabled. Use a separate queue database connection to preserve
events when application transactions roll back; jobs dispatch before commit.

This client queue is independent of the Pulse host's queue. To send through
an existing Redis setup in the client application, set `PULSE_TRANSPORT=queue` and
`PULSE_QUEUE_CONNECTION=redis`, configure its Redis credentials/client and
run `php artisan queue:work redis --tries=3 --timeout=30` (and the configured
PULSE_QUEUE if it is not the connection's default queue). Keep a database
worker until previously queued database jobs have drained. This does not change
the SDK's existing before-commit capture behaviour.

Auto-discovery installs HTTP exception capture and failed-job listeners. Duplicate
exception objects and the SDK's own delivery failures are excluded. Captures method
and URL, never bodies/headers/cookies/stack arguments. Enable send_identity to send
the authenticated ID, subject to project policy. Named secrets are scrubbed before
queueing. Arbitrary message strings must never contain secrets.

```php
app(\Pulse\Laravel\Client::class)->captureException($exception);
app(\Pulse\Laravel\Client::class)->captureMessage('Payment rejected', ['level' => 'warning']);
```

Queued delivery retries preserve UUID. Inspect failed_jobs for permanent failures. Normal
application exception handling and HTTP responses are preserved.

HTTP exceptions are sanitized when captured and finalized once RequestHandled supplies
the final response status, route and request ID. A handled exception can therefore
have status 200 rather than an assumed 500. Exception codes remain separate from
HTTP status codes. Jobs and CLI exceptions are delivered immediately without an HTTP
status; a 500 response alone does not create an exception event.

Diagnostics include method, URL, route name (or URI), request ID, PHP/Laravel/SDK
runtime and job name/connection/queue/attempt when available. Exception class,
code, up to 80 frames and five causes are captured; the exception code is not an
HTTP status. The middleware and report handler deduplicate the same Throwable.
Named secrets are scrubbed before the event reaches the client queue; request
bodies, cookies, frame arguments and SQL bindings are excluded. Oversized optional
context is dropped rather than losing the core event. Project privacy rules are
applied again by the Pulse server.

Reports expose Diagnostic, Stack trace, Timeline and Payload views, with scoped
copy/export. Older events stay readable without inferred status or retroactive
context. See the documentation in your [Pulse installation](https://pulse.bifrost.co.mz/docs)
for the current HTTP contract.

Optional flags: `PULSE_ENABLED`, `PULSE_WIDGET_ENABLED`, `PULSE_BROWSER_ERRORS_ENABLED`,
`PULSE_RELEASE` and `PULSE_TIMEOUT`. Advanced configuration can be published with
`php artisan vendor:publish --tag=pulse-config` to `config/pulse-sdk.php`, avoiding
a collision with Laravel Pulse's config. Legacy PULSESERVER_* backend variables
and PHP class names remain compatible. A legacy queue connection selects queue
mode unless PULSE_TRANSPORT overrides it. Retain aliases until old jobs drain.

Restrictive CSP must permit the local `/_pulse/sdk.js` script, the Pulse asset
origin in script-src and the API origin in connect-src. Set `pulse-sdk.nonce` at
request time if the application's policy requires nonces. No inline executable
JavaScript is injected. Remote assets and custom elements retain their existing
technical names for server compatibility. Remote project privacy settings still
govern collection; installation never grants additional collection permissions.
