<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Pulse\Laravel\Client;
use Pulse\Laravel\PulseServiceProvider;
use Pulse\Laravel\SendError;

$applicationPath = $argv[1] ?? '';
if (! is_file($applicationPath.'/bootstrap/app.php')) {
    throw new RuntimeException('Pass the path to a disposable Laravel application with the SDK installed.');
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'SESSION_DRIVER' => 'array',
    'CACHE_DRIVER' => 'array',
    'CACHE_STORE' => 'array',
    'LOG_CHANNEL' => 'stderr',
    'LOG_LEVEL' => 'emergency',
    'PULSE_ENABLED' => 'true',
    'PULSE_URL' => 'https://pulse.example.test',
    'PULSE_KEY' => 'ps_sk_PRIVATE_TEST',
    'PULSE_PUBLIC_KEY' => 'ps_pk_live_PUBLIC_TEST',
    'PULSE_ENVIRONMENT' => 'production',
    'PULSE_TRANSPORT' => 'http',
] as $name => $value) {
    putenv($name.'='.$value);
    $_ENV[$name] = $_SERVER[$name] = $value;
}

require $applicationPath.'/vendor/autoload.php';
$app = require $applicationPath.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

verify($app->providerIsLoaded(PulseServiceProvider::class), 'Composer discovery did not register the SDK.');
verify(! is_file($applicationPath.'/config/pulse-sdk.php'), 'The SDK must not require a published configuration.');

Http::preventStrayRequests();
Http::fake(['https://pulse.example.test/v1/errors' => Http::response([], 201)]);
Route::get('/pulse-smoke/html', fn () => response('<html><body>Client application</body></html>'));
Route::get('/pulse-smoke/json', fn () => response()->json(['ok' => true]));
Route::get('/pulse-smoke/handled', function () {
    report(new RuntimeException('Handled failure token=PRIVATE'));

    return response()->json(['ok' => true], 202);
});
$app['router']->getRoutes()->refreshNameLookups();

$request = Request::create('http://localhost/pulse-smoke/html');
$response = $kernel->handle($request);
$html = $response->getContent();
verify($response->getStatusCode() === 200, 'The original HTML response changed.');
verify(substr_count($html, 'id="pulse-sdk-config"') === 1, 'The automatic bootstrap is missing or duplicated.');
verify(str_contains($html, '/_pulse/sdk.js'), 'The browser bootstrap URL is missing.');
verify(str_contains($html, 'ps_pk_live_PUBLIC_TEST'), 'The public key is missing.');
verify(! str_contains($html, 'ps_sk_PRIVATE_TEST'), 'The secret key leaked into HTML.');
$kernel->terminate($request, $response);

$request = Request::create('http://localhost/pulse-smoke/json');
$response = $kernel->handle($request);
verify(json_decode($response->getContent(), true) === ['ok' => true], 'The SDK altered a JSON response.');
$kernel->terminate($request, $response);

$request = Request::create('http://localhost/_pulse/sdk.js');
$response = $kernel->handle($request);
verify($response->getStatusCode() === 200, 'The package browser asset route did not register.');
verify(str_contains($response->headers->get('Content-Type'), 'application/javascript'), 'The browser asset MIME type is incorrect.');
$kernel->terminate($request, $response);

config(['pulse-sdk.public_key' => 'ps_sk_PRIVATE_TEST']);
$request = Request::create('http://localhost/pulse-smoke/html');
$response = $kernel->handle($request);
verify(! str_contains($response->getContent(), 'pulse-sdk-config'), 'A secret key was accepted as a browser key.');
$kernel->terminate($request, $response);
config(['pulse-sdk.public_key' => 'ps_pk_live_PUBLIC_TEST']);

Queue::fake();
config(['pulse-sdk.transport' => 'queue', 'pulse-sdk.connection' => 'database']);
$client = new Client;
$eventId = $client->captureException(new RuntimeException('Queued failure'), ['context' => ['password' => 'PRIVATE']]);
$queued = Queue::pushed(SendError::class);
verify($eventId !== null && $queued->count() === 1, 'The queue transport did not enqueue exactly one event.');
verify($queued->first()->event['context']['password'] === '[redacted]', 'Queued diagnostics exposed a password.');
config(['pulse-sdk.connection' => 'sync']);
verify((new Client)->captureMessage('Ignored sync queue') === null, 'Sync queue delivery must be refused.');

config(['pulse-sdk.transport' => 'http']);
$app->instance('request', Request::create('/'));
$client = new Client;
$exception = new RuntimeException('CLI failure token=PRIVATE');
$eventId = $client->captureException($exception);
verify($client->captureException($exception) === null, 'The same exception was captured twice.');
$recorded = Http::recorded();
verify($recorded->count() === 1, 'CLI delivery must work without a worker.');
$payload = $recorded->first()[0]->data();
verify($payload['event_id'] === $eventId, 'Delivery changed the event UUID.');
verify(! str_contains(json_encode($payload), 'PRIVATE'), 'CLI diagnostics exposed a secret.');

$request = Request::create('http://localhost/pulse-smoke/handled');
$response = $kernel->handle($request);
verify($response->getStatusCode() === 202, 'Capturing an exception changed the handled response.');
verify(Http::recorded()->count() === 1, 'HTTP delivery was not deferred until termination.');
$kernel->terminate($request, $response);
verify(Http::recorded()->count() === 2, 'The HTTP exception was not delivered exactly once.');
$payload = Http::recorded()->last()[0]->data();
verify($payload['diagnostics']['request']['status'] === 202, 'The actual final HTTP status was lost.');

$app->instance('request', Request::create('/'));
Http::fake(fn () => throw new RuntimeException('Transport unavailable'));
verify((new Client)->captureException(new RuntimeException('Original failure')) !== null, 'Transport failure replaced exception capture.');
verify(is_a('PulseServer\\Laravel\\SendError', SendError::class, true), 'Previously queued job names are not compatible.');

echo json_encode(['php' => PHP_VERSION, 'laravel' => $app::VERSION, 'result' => 'passed'], JSON_THROW_ON_ERROR).PHP_EOL;
