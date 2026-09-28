<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'locale' => ['required', Rule::in(config('bora.locales'))],
            // Changing the login e-mail needs the password: a hijacked session cannot take the account over.
            'current_password' => [Rule::requiredIf(fn () => $this->input('email') !== $this->user()->email), 'nullable', 'string', 'current_password'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
