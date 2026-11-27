<?php

namespace Pulse\Laravel;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrowserScript
{
    public function __invoke(): BinaryFileResponse
    {
        return response()->file(__DIR__.'/../resources/pulse.js', [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
