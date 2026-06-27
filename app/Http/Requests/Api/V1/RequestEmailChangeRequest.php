<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim($this->string('email')->toString())),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::notIn([$this->user()->email]),
                Rule::unique('users', 'email')->ignore($this->user()->id),
                Rule::unique('users', 'pending_email')->ignore($this->user()->id),
            ],
            'current_password' => ['required', 'string', 'current_password'],
        ];
    }
}
