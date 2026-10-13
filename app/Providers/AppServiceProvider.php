<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\Estate;
use App\Models\Faq;
use App\Models\Investment;
use App\Models\Media;
use App\Models\Page;
use App\Models\PayoutAccount;
use App\Models\Property;
use App\Models\PropertyHolding;
use App\Models\Role;
use App\Models\ServiceRequest;
use App\Models\Setting;
use App\Models\Testimonial;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Identity\FakeIdentityVerifier;
use App\Services\Identity\IdentityVerifier;
use App\Services\Identity\PersonaIdentityVerifier;
use App\Services\Mail\ZeptoMailService;
use App\Services\Mail\ZeptoMailTransport;
use App\Services\Media\CloudinaryService;
use App\Services\Payments\PaystackService;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TermiiSmsSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CloudinaryService::class, fn () => new CloudinaryService(config('services.cloudinary')));
        $this->app->singleton(PaystackService::class, fn () => new PaystackService(config('services.paystack')));
        $this->app->singleton(ZeptoMailService::class, fn () => new ZeptoMailService(config('services.zeptomail')));

        $this->app->bind(IdentityVerifier::class, function () {
            $driver = config('services.identity.driver');

            if ($driver === 'persona') {
                return new PersonaIdentityVerifier(config('services.persona'));
            }

            if ($driver === 'fake' && ! $this->app->isProduction()) {
                return new FakeIdentityVerifier;
            }

            throw new InvalidArgumentException("Identity driver [$driver] is not available in this environment.");
        });

        $this->app->bind(SmsSender::class, fn () => match (config('services.sms.driver')) {
            'termii' => new TermiiSmsSender(config('services.termii')),
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        // Catch N+1s and silently-dropped mass assignment during development and tests.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();

        Relation::enforceMorphMap([
            'user' => User::class,
            'estate' => Estate::class,
            'investment' => Investment::class,
            'property' => Property::class,
            'holding' => PropertyHolding::class,
            'service_request' => ServiceRequest::class,
            'testimonial' => Testimonial::class,
            'transaction' => Transaction::class,
            'role' => Role::class,
            'faq' => Faq::class,
            'media' => Media::class,
            'document' => Document::class,
            'payout_account' => PayoutAccount::class,
            'page' => Page::class,
            'setting' => Setting::class,
        ]);

        Mail::extend('zeptomail', fn () => new ZeptoMailTransport($this->app->make(ZeptoMailService::class)));

        $this->configureRateLimiting();

        Event::listen(JobFailed::class, function (JobFailed $event) {
            Log::error('queue.job_failed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'error' => $event->exception->getMessage(),
            ]);
        });
    }

    /** Rate limits per contract §1.10. 429 responses always carry Retry-After. */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by('auth:'.$request->ip().'|'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
        ]);

        RateLimiter::for('codes', function (Request $request) {
            $key = $request->user()?->id ?: mb_strtolower((string) $request->input('email')) ?: $request->ip();

            return [
                Limit::perMinute(3)->by('codes-m:'.$key),
                Limit::perHour(10)->by('codes-h:'.$key),
            ];
        });

        RateLimiter::for('code-verify', fn (Request $request) => Limit::perMinute(10)->by('verify:'.($request->user()?->id ?: $request->ip().'|'.mb_strtolower((string) $request->input('email')))));

        RateLimiter::for('public-forms', fn (Request $request) => Limit::perHour(10)->by('forms:'.$request->ip()));

        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(10)->by('pay:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by('upload:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('webhook:'.$request->ip()));
    }
}
