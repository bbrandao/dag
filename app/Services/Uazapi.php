<?php

namespace App\Services;

use App\Support\Phone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class Uazapi
{
    public function sendOtp(string $phone, #[\SensitiveParameter] string $code): void
    {
        $url = config('dag.uazapi.url');
        $token = config('dag.uazapi.token');
        $sender = config('dag.uazapi.sender');

        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! is_string($token) || $token === '' || ! is_string($sender) || ! preg_match(Phone::PATTERN, $sender)) {
            Log::warning('WhatsApp OTP: gateway não configurado.');

            return;
        }

        $gateway = Http::baseUrl(rtrim($url, '/'))
            ->acceptJson()
            ->withHeaders(['token' => $token])
            ->withoutRedirecting()
            ->connectTimeout(2);

        try {
            $status = (clone $gateway)->timeout(2)->get('/instance/status');

            if (! $status->successful()) {
                Log::warning('WhatsApp OTP: consulta da instância recusada.', ['status' => $status->status()]);

                return;
            }

            $connected = $status->json('status.connected') ?? ($status->json('instance.status') === 'connected');

            if ($connected !== true) {
                Log::warning('WhatsApp OTP: instância desconectada.');

                return;
            }

            $pairedNumber = $this->phoneFromJid($status->json('status.jid'))
                ?? $this->phoneFromJid($status->json('instance.owner'));

            if ($pairedNumber === null) {
                Log::warning('WhatsApp OTP: a instância conectada não informou o telefone pareado.');

                return;
            }

            if ($pairedNumber !== substr($sender, 1)) {
                Log::warning('WhatsApp OTP: telefone pareado difere de WHATSAPP_SENDER_NUMBER.', [
                    'configured_digits' => strlen(substr($sender, 1)),
                    'paired_digits' => strlen($pairedNumber),
                ]);

                return;
            }

            $response = $gateway->timeout(5)->post('/send/text', [
                'number' => substr($phone, 1),
                'text' => "DAG · Seu código de acesso é {$code}. Ele expira em 5 minutos. Não compartilhe.",
                'linkPreview' => false,
            ]);
        } catch (ConnectionException) {
            Log::warning('WhatsApp OTP: resultado do envio indeterminado.');

            return;
        }

        if (! $response->successful() || $response->json('error')) {
            Log::warning('WhatsApp OTP: envio recusado pelo gateway.', ['status' => $response->status()]);
        }
    }

    private function phoneFromJid(mixed $jid): ?string
    {
        if (is_array($jid)) {
            $server = $jid['server'] ?? $jid['Server'] ?? null;

            if ($server !== null && ! in_array($server, ['s.whatsapp.net', 'c.us'], true)) {
                return null;
            }

            $jid = $jid['user'] ?? $jid['User'] ?? null;
        }

        if (! is_string($jid) || ! preg_match('/^\+?([1-9][0-9]{7,14})(?:(?:\.[0-9]+)?(?::[0-9]+)?@(?:s\.whatsapp\.net|c\.us))?$/D', $jid, $parts)) {
            return null;
        }

        return $parts[1];
    }
}
