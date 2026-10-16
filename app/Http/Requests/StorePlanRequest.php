<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('super-admin');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'price_idr' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:3660'],
            'peer_limit' => ['required', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
