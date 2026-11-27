<?php

use Illuminate\Support\Facades\Route;
use Pulse\Laravel\BrowserScript;

Route::get('/_pulse/sdk.js', BrowserScript::class)->name('pulse.sdk.script');
