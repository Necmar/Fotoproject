<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'locale' => $this->locale,
            'email_verified' => $this->hasVerifiedEmail(),
            'last_login_at' => array_key_exists('last_login_at', $this->resource->getAttributes())
                ? $this->last_login_at?->toIso8601String()
                : null,
            'company' => $this->when(
                $this->isCompanyOwner(),
                fn () => [
                    'id' => $this->company->id,
                    'name' => $this->company->name,
                    'status' => $this->company->status->value,
                    'has_logo' => $this->company->logo_path !== null,
                    'logo_url' => $this->company->logo_path ? route('api.company.logo.show', ['v' => substr(md5($this->company->logo_path), 0, 8)]) : null,
                ],
            ),
        ];
    }
}
