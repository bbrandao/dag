<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ChildController
{
    public function store(Request $request): RedirectResponse
    {
        $guardian = $request->user();
        abort_unless($guardian->role === User::GUARDIAN, 403);

        $today = now($guardian->family->timezone)->toDateString();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'phone' => ['required', 'string', Phone::RULE],
            'authorized' => ['accepted'],
        ], [
            'name.required' => 'Informe o nome.',
            'name.max' => 'Use até 120 caracteres no nome.',
            'birth_date.required' => 'Informe a data de nascimento.',
            'birth_date.date_format' => 'Informe uma data de nascimento válida.',
            'birth_date.before_or_equal' => 'A data de nascimento deve ser hoje ou anterior.',
            'phone.required' => 'Informe o WhatsApp.',
            'phone.regex' => 'Informe o WhatsApp com DDI, como +5571999999999.',
            'authorized.accepted' => 'Confirme que você tem autorização para fazer o cadastro.',
        ]);

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
}
