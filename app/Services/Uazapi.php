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
        $this->sendText($phone, "DAG · Seu código de acesso é {$code}. Ele expira em 5 minutos. Não compartilhe.", 'OTP');
    }

    public function sendText(string $phone, #[\SensitiveParameter] string $message, string $purpose = 'avisos'): string
    {
        $url = config('dag.uazapi.url');
        $token = config('dag.uazapi.token');
        $sender = config('dag.uazapi.sender');

        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || ! is_string($token) || $token === '' || ! is_string($sender) || ! preg_match(Phone::PATTERN, $sender)) {
            Log::warning("WhatsApp {$purpose}: gateway não configurado.");

            return 'failed';
        }

        $gateway = Http::baseUrl(rtrim($url, '/'))
            ->acceptJson()
            ->withHeaders(['token' => $token])
            ->withoutRedirecting()
            ->connectTimeout(2);

        $sending = false;
        try {
            $status = (clone $gateway)->timeout(2)->get('/instance/status');

            if (! $status->successful()) {
                Log::warning("WhatsApp {$purpose}: consulta da instância recusada.", ['status' => $status->status()]);

                return $status->status() === 429 || $status->serverError() ? 'pending' : 'failed';
            }

            $connected = $status->json('status.connected') ?? ($status->json('instance.status') === 'connected');

            if ($connected !== true) {
                Log::warning("WhatsApp {$purpose}: instância desconectada.");

                return 'pending';
            }

            $pairedNumber = $this->phoneFromJid($status->json('status.jid'))
                ?? $this->phoneFromJid($status->json('instance.owner'));

            if ($pairedNumber === null) {
                Log::warning("WhatsApp {$purpose}: a instância conectada não informou o telefone pareado.");

                return 'failed';
            }

            if ($pairedNumber !== substr($sender, 1)) {
                Log::warning("WhatsApp {$purpose}: telefone pareado difere de WHATSAPP_SENDER_NUMBER.", [
                    'configured_digits' => strlen(substr($sender, 1)),
                    'paired_digits' => strlen($pairedNumber),
                ]);

                return 'failed';
            }

            $sending = true;
            $response = $gateway->timeout(5)->post('/send/text', [
                'number' => substr($phone, 1),
                'text' => $message,
                'linkPreview' => false,
            ]);
        } catch (ConnectionException) {
            Log::warning("WhatsApp {$purpose}: falha de conexão com o gateway.", ['sending' => $sending]);

            return $sending ? 'unknown' : 'pending';
        }

        if (! $response->successful() || $response->json('error')) {
            Log::warning("WhatsApp {$purpose}: envio não confirmado pelo gateway.", ['status' => $response->status()]);

            return match (true) {
                $response->status() === 429 => 'pending',
                $response->serverError() => 'unknown',
                default => 'failed',
            };
        }

        return 'sent';
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
