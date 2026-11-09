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
            'permission' => ['accepted'],
        ], [
            'phone.required' => 'Informe seu WhatsApp.',
            'phone.regex' => 'Informe o WhatsApp com DDI, como +5571999999999.',
            'permission.accepted' => 'Autorize o envio do código pelo WhatsApp para continuar.',
        ]);

        $request->session()->put('otp_challenge', $otp->issue($data['phone']));

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

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('otp_challenge');
        $request->session()->put('account_version', $user->session_version);

        return redirect()->route('dashboard');
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->session()->forget('otp_challenge');

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
