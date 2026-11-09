<?php

namespace App\Http\Controllers;

use App\Services\OtpService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class OtpController
{
    public function show(Request $request): Response
    {
        Inertia::encryptHistory();

        return Inertia::render('Login', [
            'awaitingCode' => $request->session()->has('otp_challenge'),
        ]);
    }

    public function issue(Request $request, OtpService $otp): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', Phone::RULE],
            'remember' => ['sometimes', 'boolean'],
        ], [
            'phone.required' => 'Informe seu WhatsApp.',
            'phone.regex' => 'Informe o WhatsApp com DDI, como +5571999999999.',
        ]);

        $request->session()->put([
            'otp_challenge' => $otp->issue($data['phone']),
            'otp_remember' => (bool) ($data['remember'] ?? false),
        ]);

        return redirect()->route('login');
    }

    public function verify(Request $request, OtpService $otp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/D']], [
            'code.required' => 'Informe o código.',
            'code.regex' => 'Digite os seis dígitos do código.',
        ]);
        $id = $request->session()->get('otp_challenge');
        $user = is_string($id) ? $otp->verify($id, $data['code']) : null;

        if (! $user) {
            return redirect()->route('login')->withErrors(['code' => 'Código inválido ou expirado. Confira o código ou solicite outro.']);
        }

        $guard = Auth::guard('web');
        $guard->setRememberDuration(30 * 24 * 60);
        $guard->login($user, $request->session()->pull('otp_remember', false));
        $request->session()->regenerate();
        $request->session()->forget('otp_challenge');
        $request->session()->put('account_version', $user->session_version);

        $intended = $request->session()->pull('url.intended');
        $path = is_string($intended) ? parse_url($intended, PHP_URL_PATH) : null;

        return is_string($path) && preg_match('~^/(?:app/missoes|guardian/revisoes)/[0-9a-f-]{36}$~D', $path)
            ? redirect($path)
            : redirect()->route('dashboard');
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget(['otp_challenge', 'otp_remember']);

        return redirect()->route('login');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Inertia::clearHistory();

        return redirect()->route('login');
    }
}
