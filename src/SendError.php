<?php

namespace Pulse\Laravel;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

class SendError implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public array $event) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $url = rtrim((string) config('pulse-sdk.api_base'), '/');
        if (! str_starts_with($url, 'https://') && ! in_array(parse_url($url, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
            $this->fail('Pulse requires HTTPS.');

            return;
        }
        $timeout = max(0.1, min(10, (float) config('pulse-sdk.timeout', 2)));
        $response = Http::acceptJson()->withToken(config('pulse-sdk.key'))->withoutRedirecting()
            ->connectTimeout(min(1, $timeout))->timeout($timeout)->post($url.'/v1/errors', $this->event);
        if ($response->status() === 429) {
            if ($this->attempts() >= $this->tries) {
                $this->fail('Pulse rate limit persisted.');
            } else {
                $this->release(max(1, min(3600, (int) $response->header('Retry-After') ?: 60)));
            }
        } elseif ($response->serverError()) {
            $response->throw();
        } elseif (! $response->successful()) {
            $this->fail('Pulse rejected event: '.$response->status());
        }
    }
}
