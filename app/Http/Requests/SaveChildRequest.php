<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

final class SaveChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === User::GUARDIAN;
    }

    public function rules(): array
    {
        $today = now($this->user()->family->timezone)->toDateString();
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'phone' => ['required', 'string', Phone::RULE],
        ];

        if ($this->isMethod('POST')) {
            $rules['authorized'] = ['accepted'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome.',
            'name.max' => 'Use até 120 caracteres no nome.',
            'birth_date.required' => 'Informe a data de nascimento.',
            'birth_date.date_format' => 'Informe uma data de nascimento válida.',
            'birth_date.before_or_equal' => 'A data de nascimento deve ser hoje ou anterior.',
            'phone.required' => 'Informe o WhatsApp.',
            'phone.regex' => 'Informe o WhatsApp com DDI, como +5571999999999.',
            'authorized.accepted' => 'Confirme que você tem autorização para fazer o cadastro.',
        ];
    }
}
