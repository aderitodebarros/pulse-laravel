<?php

namespace Pulse\Laravel;

use DOMDocument;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InjectWidget
{
    public function inject(Request $request, Response $response): void
    {
        $key = config('pulse-sdk.public_key');
        $base = rtrim((string) config('pulse-sdk.api_base'), '/');
        if (! config('pulse-sdk.enabled') || ! is_string($key) || ! preg_match('/^(?:ps|fb)_pk_[A-Za-z0-9_]+$/', $key)
            || (! config('pulse-sdk.widget') && ! config('pulse-sdk.browser_errors'))
            || ! filter_var($base, FILTER_VALIDATE_URL)
            || (parse_url($base, PHP_URL_SCHEME) !== 'https' && ! in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true))
            || $response instanceof StreamedResponse || $response instanceof BinaryFileResponse
            || $request->wantsJson() || $request->hasHeader('X-Livewire') || $request->is('up', '_pulse/*')
            || ($request->ajax() && ! $request->hasHeader('X-Livewire-Navigate'))
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }
        $content = $response->getContent();
        if (! is_string($content) || stripos($content, '</body>') === false) {
            return;
        }
        $document = new DOMDocument;
        $document->loadHTML($content, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        if ($document->getElementById('pulse-sdk-config')) {
            return;
        }
        $hasWidget = $document->getElementsByTagName('pulseserver-feedback')->length > 0
            || $document->getElementsByTagName('feedbackserver-widget')->length > 0;
        $nonce = config('pulse-sdk.nonce');
        $attribute = filled($nonce) ? ' nonce="'.e($nonce).'"' : '';
        $config = json_encode([
            'apiBase' => $base, 'key' => $key,
            'environment' => config('pulse-sdk.environment'), 'release' => config('pulse-sdk.release'),
            'browserErrors' => (bool) config('pulse-sdk.browser_errors'),
            'widget' => (bool) config('pulse-sdk.widget') && ! $hasWidget,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        $snippet = '<script type="application/json" id="pulse-sdk-config"'.$attribute.'>'.$config.'</script>'
            .'<script src="'.e(route('pulse.sdk.script', [], false)).'" defer data-navigate-once'.$attribute.'></script>';
        $response->setContent(preg_replace_callback('~</body>~i', fn ($match) => $snippet.$match[0], $content, 1));
        $response->headers->remove('Content-Length');
        $response->headers->remove('ETag');
    }
}
