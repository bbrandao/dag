<?php

namespace App\Console\Commands;

use App\Models\Family;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ProvisionFamily extends Command
{
    protected $signature = 'dag:provision-family';

    protected $description = 'Cadastra uma família e seu primeiro responsável';

    public function handle(): int
    {
        $data = [
            'family' => trim((string) $this->ask('Nome da família')),
            'name' => trim((string) $this->ask('Nome do responsável')),
            'relationship' => $this->choice('Parentesco', User::RELATIONSHIPS),
            'phone' => trim((string) $this->secret('WhatsApp do responsável com DDI, por exemplo +5571999999999')),
            'timezone' => $this->ask('Fuso horário', 'America/Bahia'),
        ];
        $validator = Validator::make($data, [
            'family' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'relationship' => ['required', Rule::in(User::RELATIONSHIPS)],
            'phone' => ['required', 'string', Phone::RULE],
            'timezone' => ['required', 'timezone'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($data): void {
                $family = Family::create(['name' => $data['family'], 'timezone' => $data['timezone']]);
                $guardian = User::create([
                    'family_id' => $family->id,
                    'name' => $data['name'],
                    'role' => User::GUARDIAN,
                    'relationship' => $data['relationship'],
                    'phone' => $data['phone'],
                    'phone_hash' => Phone::fingerprint($data['phone']),
                ]);
                DB::table('audit_events')->insert([
                    'family_id' => $family->id,
                    'subject_id' => $guardian->id,
                    'event' => 'family.provisioned',
                    'recorded_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->error('Não foi possível cadastrar a família com esse número.');

            return self::FAILURE;
        }

        $this->info('Família cadastrada. O responsável já pode solicitar um código.');

        return self::SUCCESS;
    }
}
