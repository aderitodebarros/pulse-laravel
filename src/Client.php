<?php

namespace Pulse\Laravel;

use Composer\InstalledVersions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Client
{
    private const PACKAGE = 'bifrost-mz/pulse-laravel';

    private \WeakMap $reported;

    public array $context = [];

    public array $diagnostics = [];

    public function __construct()
    {
        $this->reported = new \WeakMap;
    }

    public static function version(): string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled(self::PACKAGE)) {
            return 'unknown';
        }

        return InstalledVersions::getPrettyVersion(self::PACKAGE) ?: 'unknown';
    }

    public function captureException(Throwable $error, array $context = []): ?string
    {
        if (isset($this->reported[$error])) {
            return null;
        }
        $this->reported[$error] = true;
        $frames = array_merge([['file' => $error->getFile(), 'line' => $error->getLine()]], $error->getTrace());
        $database = $error instanceof QueryException;
        $causes = [];
        for ($cause = $error->getPrevious(); $cause && count($causes) < 5; $cause = $cause->getPrevious()) {
            $causes[] = ['class' => $cause::class, 'code' => (string) $cause->getCode(), 'message' => $database ? 'Database driver error' : mb_substr($cause->getMessage(), 0, 5000),
                'file' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $cause->getFile()), 'line' => $cause->getLine()];
        }

        return $this->captureMessage($database ? 'Database query failed' : ($error->getMessage() ?: $error::class), $context + [
            'kind' => isset($this->diagnostics['job']) || isset($context['diagnostics']['job']) ? 'job' : 'exception',
            'exception' => ['class' => $error::class, 'code' => (string) $error->getCode(), 'causes' => $causes, 'frames' => array_map(fn ($frame) => [
                'file' => mb_substr(str_replace(base_path().DIRECTORY_SEPARATOR, '', $frame['file'] ?? 'unknown'), 0, 2000),
                'line' => isset($frame['line']) ? max(1, $frame['line']) : null, 'function' => mb_substr(($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? ''), 0, 300),
                'in_app' => ! str_contains($frame['file'] ?? '', DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR),
            ], array_slice($frames, 0, 80))],
        ]);
    }

    public function captureMessage(string $message, array $context = []): ?string
    {
        if (! config('pulse-sdk.enabled') || ! config('pulse-sdk.key') || ! config('pulse-sdk.api_base')) {
            return null;
        }
        if (config('pulse-sdk.transport') === 'queue' && config('pulse-sdk.connection') === 'sync') {
            return null;
        }
        $event = [
            'event_id' => (string) Str::uuid(), 'environment' => config('pulse-sdk.environment'),
            'kind' => $context['kind'] ?? 'message',
            'platform' => 'php', 'level' => $context['level'] ?? 'error', 'message' => mb_substr($message, 0, 5000),
            'occurred_at' => now()->toIso8601String(), 'release' => config('pulse-sdk.release'),
            'context' => $this->context + ($context['context'] ?? []),
            'diagnostics' => ($context['diagnostics'] ?? []) + $this->diagnostics + [
                'runtime' => ['php' => PHP_VERSION, 'framework' => 'Laravel '.Application::VERSION,
                    'sdk' => mb_substr(self::PACKAGE.'@'.self::version(), 0, 60)],
            ],
        ];
        if (isset($this->diagnostics['request']['url'])) {
            $event['url'] = $this->diagnostics['request']['url'];
        }
        foreach (['exception', 'fingerprint'] as $field) {
            if (isset($context[$field])) {
                $event[$field] = $context[$field];
            }
        }
        if (config('pulse-sdk.send_identity') && isset($context['user'])) {
            $event['user'] = $context['user'];
        }
        $event = $this->scrub($event);
        $request = app()->bound('request') ? request() : null;
        if ($event['kind'] === 'exception' && $request?->attributes->get('pulse_sdk_http_active')) {
            $event['diagnostics']['request'] = $this->scrub($this->requestDiagnostics($request));
            $event['url'] = $event['diagnostics']['request']['url'];
            $pending = $request->attributes->get('pulse_sdk_pending', []);
            $pending[] = $event;
            $request->attributes->set('pulse_sdk_pending', $pending);

            return $event['event_id'];
        }

        return $this->send($event);
    }

    public function finishRequest(Request $request, Response $response): void
    {
        $pending = $request->attributes->get('pulse_sdk_pending', []);
        $request->attributes->remove('pulse_sdk_pending');
        $request->attributes->set('pulse_sdk_http_active', false);
        foreach ($pending as $event) {
            try {
                $event['diagnostics']['request'] = $this->scrub($this->requestDiagnostics($request) + ['status' => $response->getStatusCode()]);
                $this->send($event);
            } catch (Throwable) {
                // Diagnostics must never affect the completed HTTP response.
            }
        }
    }

    private function requestDiagnostics(Request $request): array
    {
        return ['method' => $request->method(), 'url' => $request->url(),
            'route' => $request->route()?->getName() ?: $request->route()?->uri(),
            'request_id' => $request->attributes->get('pulse_request_id')];
    }

    private function send(array $event): ?string
    {
        if (strlen(json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)) > 250000) {
            unset($event['context']);
            $event['truncated'] = ['context'];
        }
        if (strlen(json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE)) > 250000) {
            return null;
        }
        try {
            if (config('pulse-sdk.transport') === 'queue') {
                SendError::dispatch($event)->onConnection(config('pulse-sdk.connection'))->onQueue(config('pulse-sdk.queue'))->beforeCommit();
            } else {
                $deliver = static function () use ($event): void {
                    try {
                        (new SendError($event))->handle();
                    } catch (Throwable) {
                        // Delivery cannot replace the application's response or exception.
                    }
                };
                if (request()->attributes->get('pulse_sdk_http_request')) {
                    app()->terminating($deliver);
                } else {
                    $deliver();
                }
            }
        } catch (Throwable) {
            // Reporting must never replace the application's original exception.
            return null;
        }

        return $event['event_id'];
    }

    private function scrub(mixed $value, string $key = '', int $depth = 0): mixed
    {
        if ($depth > 12) {
            return '[truncated]';
        }
        if (preg_match('/password|secret|token|authorization|cookie|api[_-]?key|credit.?card/i', $key)) {
            return '[redacted]';
        }
        if (preg_match('/^(body|headers|bindings|arguments|args|form_data)$/i', $key)) {
            return '[not collected]';
        }
        if (is_array($value)) {
            $result = [];
            foreach (array_slice($value, 0, 100, true) as $k => $v) {
                $result[$k] = $this->scrub($v, (string) $k, $depth + 1);
            }

            return $result;
        }
        if (is_string($value)) {
            return mb_substr(preg_replace([
                '/(https?:\/\/[^\s?#]+)[?#][^\s]*/', '/Bearer\s+\S+/i', '/\b(?:fb|ps)_(?:sk|pk)_[A-Za-z0-9_]+/',
                '/\b(password|secret|token|authorization|cookie|api[_-]?key)\s*[:=]\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s,;&]+)/i',
                '~(https?://)[^/\s:@]+:[^@/\s]+@~i',
            ], ['$1', 'Bearer [redacted]', '[redacted]', '$1=[redacted]', '$1[redacted]@'], $value), 0, 10000);
        }

        return is_scalar($value) || $value === null ? $value : '[object]';
    }
}
