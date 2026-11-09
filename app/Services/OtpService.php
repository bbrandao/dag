<?php

namespace App\Services;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

use function Illuminate\Support\defer;

final class OtpService
{
    public function __construct(private readonly Uazapi $gateway) {}

    public function issue(string $phone): string
    {
        $id = (string) Str::uuid();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $issued = DB::transaction(function () use ($phone, $id, $code): bool {
            $user = User::where('phone_hash', Phone::fingerprint($phone))
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return false;
            }

            $now = now();
            $challenges = DB::table('otp_challenges')->where('user_id', $user->id);

            // O bloqueio do usuário também cobre pedidos simultâneos de sessões diferentes.
            if (! app()->isLocal()
                && (clone $challenges)->where('expires_at', '>', $now->copy()->addMinutes(4))->exists()) {
                return false;
            }

            (clone $challenges)->whereNull('consumed_at')->update(['consumed_at' => $now]);
            DB::table('otp_challenges')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'digest' => $this->digest($id, $code),
                'expires_at' => $now->copy()->addMinutes(5),
            ]);

            return true;
        });

        if ($issued) {
            defer(fn () => $this->gateway->sendOtp($phone, $code));
        }

        return $id;
    }

    public function verify(string $id, #[\SensitiveParameter] string $code): ?User
    {
        $userId = DB::table('otp_challenges')->where('id', $id)->value('user_id');

        if (! $userId) {
            return null;
        }

        return DB::transaction(function () use ($userId, $id, $code): ?User {
            $user = User::whereKey($userId)->lockForUpdate()->first();
            $query = DB::table('otp_challenges')->where('id', $id);
            $challenge = $query->first();

            if (! $user?->active || ! $challenge || $challenge->consumed_at
                || $challenge->attempts >= 5 || now()->gte(Carbon::parse($challenge->expires_at))) {
                return null;
            }

            if (! hash_equals($challenge->digest, $this->digest($id, $code))) {
                $query->increment('attempts');

                return null;
            }

            $query->update(['consumed_at' => now()]);

            return $user;
        });
    }

    private function digest(string $id, #[\SensitiveParameter] string $code): string
    {
        $key = config('dag.otp_key');

        if (! is_string($key) || strlen($key) < 32) {
            throw new LogicException('Configure DAG_OTP_KEY com uma chave de pelo menos 32 caracteres.');
        }

        return hash_hmac('sha256', $id.':'.$code, $key);
    }
}
