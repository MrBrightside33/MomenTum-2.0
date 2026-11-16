<?php

namespace App\Providers;

use App\Listeners\AuditAuthSubscriber;
use App\View\Composers\AdminNavigationComposer;
use App\View\Composers\NavigationComposer;
use App\View\Composers\TaskModalComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce HTTPS in production.
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        // Eloquent development safeguards.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Authentication audit subscriber.
        Event::subscribe(AuditAuthSubscriber::class);

        // View composers.
        View::composer('tasks.create-modal', TaskModalComposer::class);
        View::composer('admin.*', AdminNavigationComposer::class);

        View::composer(
            ['layouts.partials.sidebar', 'layouts.partials.header'],
            NavigationComposer::class
        );

        // Login rate limiter.
        RateLimiter::for('login', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email . '|' . $request->ip());
        });

        // Password reset rate limiter.
        RateLimiter::for('password-reset', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perHour(3)->by($email . '|' . $request->ip());
        });
    }
}