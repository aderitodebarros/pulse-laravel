<?php

namespace Pulse\Laravel;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CaptureRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = app(Client::class);
        $id = $request->attributes->get('pulse_request_id') ?: (string) Str::uuid();
        $request->attributes->set('pulse_request_id', $id);
        $request->attributes->set('pulse_sdk_http_active', true);
        $request->attributes->set('pulse_sdk_http_request', true);
        $client->diagnostics['request'] = ['method' => $request->method(), 'url' => $request->url(),
            'route' => $request->route()?->getName() ?: $request->route()?->uri(), 'request_id' => $id];
        $client->context = ['method' => $request->method(), 'url' => $request->url()];
        try {
            $response = $next($request);
            $response->headers->set('X-Request-ID', $id);

            return $response;
        } catch (Throwable $error) {
            $context = [];
            if (config('pulse-sdk.send_identity') && $request->user()) {
                $context['user'] = ['id' => (string) $request->user()->getAuthIdentifier()];
            }
            if (app(ExceptionHandler::class)->shouldReport($error)) {
                $client->captureException($error, $context);
            }
            throw $error;
        } finally {
            $client->context = [];
            unset($client->diagnostics['request']);
        }
    }
}
