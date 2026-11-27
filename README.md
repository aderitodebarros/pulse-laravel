# bifrost-mz/pulse-laravel

Laravel 10-13 / PHP 8.1+. The application must also satisfy Laravel's PHP requirements:

| PHP | Laravel |
| --- | --- |
| 8.1 | 10 |
| 8.2 | 10, 11, 12 |
| 8.3 | 10, 11, 12, 13 |
| 8.4 | 11, 12, 13 |

Legacy compatibility is not a recommendation to run unsupported software. PHP 8.1,
Laravel 10 and Laravel 11 are end-of-life; upgrade the host application when possible.
The compatibility workflow exercises real PHP/Laravel combinations in disposable
applications. Only legacy test jobs permit known vulnerable dependency versions;
their security advisories remain visible. No consumer security settings are changed.

## Installation

```sh
composer require bifrost-mz/pulse-laravel
```

For development, use a Composer path repository pointing to this directory
and require `bifrost-mz/pulse-laravel:@dev`. Configure only the application's `.env`:

```dotenv
PULSE_URL=https://pulse.bifrost.co.mz
PULSE_KEY=ps_sk_YOUR_SECRET_KEY
PULSE_PUBLIC_KEY=ps_pk_live_YOUR_PUBLIC_KEY
PULSE_ENVIRONMENT=production
```

## Versions

Releases follow semantic versioning and are published as Git tags, starting with
`v1.0.0`. See [CHANGELOG.md](CHANGELOG.md) for release notes. Composer resolves the
installed version from the tag; composer.json deliberately has no version field.

Events keep three different version identifiers:
- `diagnostics.runtime.php`: the actual PHP runtime.
- `diagnostics.runtime.framework`: the actual Laravel version.
- `diagnostics.runtime.sdk`: the package and its installed Composer version,
  for example `bifrost-mz/pulse-laravel@v1.0.0` or `@dev-main`.

`PULSE_RELEASE` identifies your application's deployment, not the SDK. Set it in
your deployment environment when you need release-based investigation. Code loaded
outside Composer reports `@unknown` rather than inventing an SDK version. The SDK
identifier is limited to 60 characters to remain compatible with deployed servers.
The full installed version is also available through `Pulse\Laravel\Client::version()`.

## Configuration

### Obtain the two credentials

In Pulse, select the workspace and project, then create a public key in **Chaves**.
Allow the host and port of the client application; an empty allowlist blocks browser
requests. The public-key copy button copies the token for `PULSE_PUBLIC_KEY`.
In the same row's menu, choose **Gerar chave secreta** and confirm. The normal secret
has `feedbacks:create` and `errors:create`; copy it once into `PULSE_KEY` using your
backend secret manager. Only its hash is stored by Pulse. Never put this token in
frontend variables, URLs, source control or support screenshots.

The secret status is not generated, active, expired or revoked. New and regenerated
secrets expire after 90 days by default; the issue modal displays the actual expiry
and permissions. A lost secret cannot be recovered. Regenerating it invalidates the
old value and preserves its permissions; update the client configuration immediately.
Regenerating only the public key leaves the secret unchanged. Revoking the secret
leaves the public key unchanged; **Revogar ambas as chaves** disables both credentials
in that row. Cancelling a confirmation does not change credentials.

Do not use the dedicated CI secret generated in Projects for `PULSE_KEY`: its only
permission is `sourcemaps:write`, not `errors:create`. CI credentials do not appear
in the widget installation picker. A workspace contains the team and subscription;
a project identifies the client application. Feedback is assigned from the
authenticated key's project, not from a project ID supplied in a request body.

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

## Troubleshooting

### Widget not visible

1. Check `PULSE_ENABLED=true`, `PULSE_WIDGET_ENABLED=true`, a valid active
   `PULSE_PUBLIC_KEY`, and the correct `PULSE_URL`. Use HTTPS except on localhost.
   The SDK does not create credentials or allow domains on your behalf.
2. Allow the application's actual host and port in Pulse. Preview domains and
   `localhost:3000` are separate origins. Never use a secret or CI credential as
   the widget key, and do not open CORS globally to bypass an origin error.
3. After editing `.env`, run `php artisan config:clear` in the client application.
   Rebuild configuration with `php artisan config:cache` during deployment and
   restart long-running application processes, including queue workers when used.
4. Inspect the original HTML for `pulse-sdk-config` and check the Network panel for
   `/_pulse/sdk.js` and the remote Pulse assets. Only the public key belongs in this
   configuration. If the local script route is missing, check package discovery
   and rebuild the client application's route cache during deployment.
5. Injection requires a full HTML response containing `</body>`. JSON, downloads,
   streams, ordinary AJAX requests, Livewire partials, `up` and `_pulse/*` are
   excluded. A separately hosted SPA does not receive injected HTML from Laravel.
   Do not initialize the browser SDK manually alongside the automatic integration.
6. If scripts load but the widget is hidden, check allowed/blocked page paths and
   the stored widget configuration. Check CSP violations and use the nonce setting
   described above when required; do not disable the application's CSP.

### Events not arriving

- `401`: check credential type, exact value, secret expiry and revocation. Update
  the relevant client secret/configuration after regenerating a key.
- `403`: check browser origins or backend permissions. CI secrets cannot submit
  feedback or error events.
- `422` for errors: inspect validation fields and verify that `PULSE_ENVIRONMENT`
  exists in the project. Both client keys must identify the same project.
- `429`: respect rate limits and the retry interval provided by the server.
- Browser errors need `PULSE_BROWSER_ERRORS_ENABLED=true`; backend reports need
  `PULSE_KEY` with `errors:create`. A 500 response without a reportable exception
  does not generate an incident by itself.
- HTTP mode is best-effort and deliberately preserves the original application
  response on delivery failure. Queue mode requires a worker consuming the exact
  configured connection and queue. Inspect `failed_jobs`; do not delete jobs to
  diagnose a problem.

For support, share the SDK/PHP/Laravel versions, a sanitized URL, status code,
environment, application release and request ID when available. Use synthetic data
and redact secrets. Never share a complete `.env` file.
