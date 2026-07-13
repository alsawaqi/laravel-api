<?php

namespace App\Providers;

 
use Laravel\Sanctum\Sanctum;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Http\Parser\Cookies;
use Illuminate\Support\ServiceProvider;
use App\Services\Checkout\PaymentGateway;
use App\Services\Checkout\AmwalPaymentGateway;
use App\Services\Payments\Amwal\AmwalSecureHash;
use App\Services\Payments\Amwal\AmwalSmartBoxService;
use App\Models\ConxDatabaseNotification;
use Laravel\Sanctum\PersonalAccessToken;
use App\Observers\ConxNotificationObserver;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, AmwalPaymentGateway::class);

        $this->app->singleton(AmwalSecureHash::class, fn () => new AmwalSecureHash(
            (string) config('services.amwal.secure_key'),
        ));

        $this->app->singleton(AmwalSmartBoxService::class, fn ($app) => new AmwalSmartBoxService(
            secureHash: $app->make(AmwalSecureHash::class),
            merchantId: (string) config('services.amwal.merchant_id'),
            terminalId: (string) config('services.amwal.terminal_id'),
            scriptUrl: (string) config('services.amwal.smartbox_url'),
            currencyId: (int) config('services.amwal.currency_id', 512),
            paymentViewType: (int) config('services.amwal.payment_view_type', 1),
            contactInfoType: (int) config('services.amwal.contact_info_type', 1),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {

    $parser = JWTAuth::parser();
    $parser->addParser(new Cookies(true));
    ConxDatabaseNotification::observe(ConxNotificationObserver::class);
     
    }
}
