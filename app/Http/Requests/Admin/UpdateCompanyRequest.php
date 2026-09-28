<?php

namespace App\Http\Requests\Admin;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isSuperAdmin();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'company_name' => ['sometimes', 'required', 'string', 'max:120'],
            'owner_name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($company->owner?->id)],
            'password' => ['nullable', 'string', Password::defaults()],
            'locale' => ['sometimes', Rule::in(config('bora.locales'))],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }
}
