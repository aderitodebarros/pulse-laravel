<?php

use Pulse\Laravel\CaptureRequest;
use Pulse\Laravel\Client;
use Pulse\Laravel\PulseServiceProvider;
use Pulse\Laravel\SendError;

// Keep previously queued jobs and explicit integrations loadable during upgrades.
spl_autoload_register(static function (string $class): void {
    $aliases = [
        'PulseServer\\Laravel\\Client' => Client::class,
        'PulseServer\\Laravel\\SendError' => SendError::class,
        'PulseServer\\Laravel\\CaptureRequest' => CaptureRequest::class,
        'PulseServer\\Laravel\\PulseServerServiceProvider' => PulseServiceProvider::class,
    ];
    if (isset($aliases[$class])) {
        class_alias($aliases[$class], $class);
    }
});
