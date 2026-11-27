<?php

namespace Pulse\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class PulseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pulse-sdk.php', 'pulse-sdk');
        $this->app->scoped(Client::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/pulse-sdk.php' => config_path('pulse-sdk.php')], 'pulse-config');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->app->make(Kernel::class)->pushMiddleware(CaptureRequest::class);
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            app(Client::class)->finishRequest($event->request, $event->response);
            app(InjectWidget::class)->inject($event->request, $event->response);
            if ($id = $event->request->attributes->get('pulse_request_id')) {
                $event->response->headers->set('X-Request-ID', $id);
            }
        });
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            app(Client::class)->diagnostics['job'] = ['name' => $event->job->resolveName(),
                'connection' => $event->connectionName, 'queue' => $event->job->getQueue(), 'attempt' => $event->job->attempts()];
        });
        Event::listen(JobProcessed::class, function (): void {
            unset(app(Client::class)->diagnostics['job']);
        });
        $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $error): void {
            foreach ($error->getTrace() as $frame) {
                if (($frame['class'] ?? null) === SendError::class) {
                    return;
                }
            }
            app(Client::class)->captureException($error);
        });
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            if ($event->job->resolveName() !== SendError::class) {
                app(Client::class)->captureException($event->exception, ['kind' => 'job',
                    'context' => ['job' => $event->job->resolveName()],
                    'diagnostics' => ['job' => ['name' => $event->job->resolveName(), 'connection' => $event->connectionName,
                        'queue' => $event->job->getQueue(), 'attempt' => $event->job->attempts()]]]);
            }
        });
    }
}
