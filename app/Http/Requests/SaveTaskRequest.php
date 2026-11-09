<?php

namespace App\Http\Requests;

use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === User::GUARDIAN;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'criteria' => ['required', 'string', 'max:1000'],
            'points' => ['required', 'integer', 'min:1', 'max:100000'],
            'frequency' => ['required', Rule::in(TaskTemplate::FREQUENCIES)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'weekday_time' => ['required', 'date_format:H:i'],
            'weekend_time' => ['required', 'date_format:H:i'],
            'photo_mode' => ['required', Rule::in(['disabled', 'optional', 'required'])],
            'daily_days' => ['exclude_unless:frequency,daily', 'required', 'array', 'min:1', 'max:7'],
            'daily_days.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'weekly_day' => ['exclude_unless:frequency,weekly', 'required', 'integer', 'between:1,7'],
            'month_day' => ['exclude_unless:frequency,monthly,yearly', 'required_if:frequency,yearly', 'nullable', 'integer', 'between:1,31'],
            'year_month' => ['exclude_unless:frequency,yearly', 'required', 'integer', 'between:1,12'],
            'child_ids' => ['required', 'array', 'min:1'],
            'child_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Preencha este campo.',
            'required_if' => 'Preencha este campo.',
            'child_ids.required' => 'Selecione pelo menos um filho.',
            'daily_days.required' => 'Selecione os dias em que a missão aparece.',
            'date_format' => 'Use uma data ou horário válido.',
            'integer' => 'Informe um número inteiro.',
            'min' => 'Informe um valor dentro do intervalo indicado.',
            'max' => 'O valor ultrapassa o limite deste campo.',
            'between' => 'Informe um valor dentro do intervalo indicado.',
        ];
    }
}
