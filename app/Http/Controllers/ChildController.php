<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class ChildController
{
    public function store(Request $request): RedirectResponse
    {
        $guardian = $request->user();
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $data = $this->validateProfile($request, $guardian, registering: true);

        try {
            DB::transaction(function () use ($guardian, $data): void {
                $child = User::create([
                    'family_id' => $guardian->family_id,
                    'name' => $data['name'],
                    'role' => User::CHILD,
                    'phone' => $data['phone'],
                    'phone_hash' => Phone::fingerprint($data['phone']),
                ]);
                DB::table('children')->insert([
                    'user_id' => $child->id,
                    'family_id' => $guardian->family_id,
                    'birth_date' => Crypt::encryptString($data['birth_date']),
                ]);
                DB::table('guardian_child_links')->insert([
                    'family_id' => $guardian->family_id,
                    'guardian_id' => $guardian->id,
                    'child_id' => $child->id,
                    'authorized_at' => now(),
                ]);
                DB::table('audit_events')->insert([
                    'family_id' => $guardian->family_id,
                    'actor_id' => $guardian->id,
                    'subject_id' => $child->id,
                    'event' => 'child.registered',
                    'recorded_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('dashboard')->withErrors(['phone' => 'Não foi possível cadastrar esse WhatsApp. Confira o número ou fale com o responsável pela operação do DAG.']);
        }

        return redirect()->route('dashboard');
    }

    public function edit(Request $request, string $child): Response
    {
        $profile = $this->linkedProfile($request->user(), $child)->firstOrFail();
        $birthDate = DB::table('children')->where('user_id', $profile->id)->value('birth_date');
        Inertia::encryptHistory();

        return Inertia::render('EditChild', [
            'child' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'birth_date' => Crypt::decryptString($birthDate),
                'phone' => $profile->phone,
            ],
        ]);
    }

    public function update(Request $request, string $child): RedirectResponse
    {
        $guardian = $request->user();
        $query = $this->linkedProfile($guardian, $child);
        $data = $this->validateProfile($request, $guardian);

        try {
            DB::transaction(function () use ($query, $guardian, $data): void {
                $profile = $query->lockForUpdate()->firstOrFail();
                $phoneChanged = $profile->phone !== $data['phone'];
                $profile->name = $data['name'];

                if ($phoneChanged) {
                    $profile->forceFill([
                        'phone' => $data['phone'],
                        'phone_hash' => Phone::fingerprint($data['phone']),
                        'session_version' => $profile->session_version + 1,
                        'remember_token' => null,
                        'whatsapp_notices' => false,
                    ]);
                }

                $profile->save();
                DB::table('children')->where('user_id', $profile->id)
                    ->update(['birth_date' => Crypt::encryptString($data['birth_date'])]);

                if ($phoneChanged) {
                    DB::table('otp_challenges')->where('user_id', $profile->id)->whereNull('consumed_at')
                        ->update(['consumed_at' => now()]);
                    DB::table('mission_notices')->where('recipient_id', $profile->id)->where('status', 'pending')
                        ->update(['status' => 'cancelled']);
                }

                DB::table('audit_events')->insert([
                    'family_id' => $guardian->family_id,
                    'actor_id' => $guardian->id,
                    'subject_id' => $profile->id,
                    'event' => $phoneChanged ? 'child.phone_changed' : 'child.updated',
                    'recorded_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return redirect()->route('children.edit', $child)
                ->withErrors(['phone' => 'Esse WhatsApp já está em uso. Informe outro número.']);
        }

        return redirect()->route('dashboard');
    }

    private function linkedProfile(User $guardian, string $child): Builder
    {
        abort_unless($guardian->role === User::GUARDIAN, 403);

        return User::whereKey($child)->where('family_id', $guardian->family_id)
            ->where('role', User::CHILD)->where('active', true)
            ->whereIn('id', DB::table('guardian_child_links')->where('family_id', $guardian->family_id)
                ->where('guardian_id', $guardian->id)->where('active', true)->select('child_id'));
    }

    private function validateProfile(Request $request, User $guardian, bool $registering = false): array
    {
        $today = now($guardian->family->timezone)->toDateString();
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'phone' => ['required', 'string', Phone::RULE],
        ];

        if ($registering) {
            $rules['authorized'] = ['accepted'];
        }

        return $request->validate($rules, [
            'name.required' => 'Informe o nome.',
            'name.max' => 'Use até 120 caracteres no nome.',
            'birth_date.required' => 'Informe a data de nascimento.',
            'birth_date.date_format' => 'Informe uma data de nascimento válida.',
            'birth_date.before_or_equal' => 'A data de nascimento deve ser hoje ou anterior.',
            'phone.required' => 'Informe o WhatsApp.',
            'phone.regex' => 'Informe o WhatsApp com DDI, como +5571999999999.',
            'authorized.accepted' => 'Confirme que você tem autorização para fazer o cadastro.',
        ]);
    }
}
