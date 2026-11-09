<?php

namespace App\Providers;

use App\Support\Phone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $limited = static function (Request $request, array $headers) {
            if ($request->header('X-Inertia')) {
                $field = $request->is('auth/otp/verify') ? 'code' : 'phone';

                return redirect()->route('login')->withErrors([
                    $field => 'Muitas tentativas. Aguarde um pouco antes de tentar novamente.',
                ]);
            }

            return response('Muitas tentativas.', 429, $headers);
        };

        RateLimiter::for('otp-issue', function (Request $request) use ($limited): array {
            $limits = [Limit::perMinute(10)->by('ip:'.$request->ip())->response($limited)];
            $phone = $request->input('phone');

            if (is_string($phone) && preg_match(Phone::PATTERN, $phone)) {
                $fingerprint = Phone::fingerprint($phone);
                $limits[] = Limit::perMinute(1)->by('minute:'.$fingerprint)->response($limited);
                $limits[] = Limit::perHour(5)->by('hour:'.$fingerprint)->response($limited);
            }

            return $limits;
        });

        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinute(20)->by($request->ip())->response($limited));
    }
}
