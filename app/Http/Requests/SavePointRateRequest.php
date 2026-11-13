<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

final class SavePointRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === User::GUARDIAN;
    }

    public function rules(): array
    {
        return ['cents_per_point' => ['required', 'integer', 'between:1,100000']];
    }

    public function messages(): array
    {
        return ['cents_per_point.between' => 'Informe de 1 a 100.000 centavos por ponto.'];
    }
}
