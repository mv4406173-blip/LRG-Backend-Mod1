<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\SchemaArtifact;
use App\Models\SchemaImport;
use App\Policies\ProjectPolicy;
use App\Policies\SchemaArtifactPolicy;
use App\Policies\SchemaImportPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(SchemaImport::class, SchemaImportPolicy::class);
        Gate::policy(SchemaArtifact::class, SchemaArtifactPolicy::class);

        Password::defaults(function () {
            $rule = Password::min(12)->max(1024);

            if ($this->app->environment('production')) {
                $rule->uncompromised();
            }

            return $rule;
        });

        VerifyEmail::createUrlUsing(function ($notifiable) {
            $frontendUrl = config('app.frontend_url');
            $backendUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(60),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );

            $path = parse_url($backendUrl, PHP_URL_PATH).'?'.parse_url($backendUrl, PHP_URL_QUERY);

            return $frontendUrl.'/email/verify?redirect='.urlencode($path);
        });

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $frontendUrl = config('app.frontend_url');

            return $frontendUrl.'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
