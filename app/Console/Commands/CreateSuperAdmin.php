<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates (or with --update, changes) the single central Super Admin.
 * Works non-interactively for Plesk: php artisan bora:super-admin --email=... --password=...
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'bora:super-admin
        {--email= : E-mailadres}
        {--name= : Naam}
        {--password= : Wachtwoord (min. 10 tekens, letters en cijfers)}
        {--update : Bestaand Super Admin-account bijwerken}';

    protected $description = 'Maak het centrale Super Admin-account aan of werk het bij';

    public function handle(): int
    {
        $existing = User::query()->where('role', UserRole::SuperAdmin)->first();

        if ($existing && ! $this->option('update')) {
            $this->error("Er bestaat al een Super Admin ({$existing->email}). Gebruik --update om die te wijzigen.");

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?: ($existing?->name ?? text('Naam', default: 'Super Admin', required: true)),
            'email' => Str::lower($this->option('email') ?: text('E-mailadres', default: $existing?->email ?? '', required: true)),
            'password' => $this->option('password') ?: password('Wachtwoord', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'.($existing ? ','.$existing->id : '')],
            'password' => ['required', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = $existing ?? new User;
        $user->fill($data);
        $user->role = UserRole::SuperAdmin;
        $user->company_id = null;
        $user->email_verified_at ??= now();
        $user->save();

        $this->info(($existing ? 'Super Admin bijgewerkt: ' : 'Super Admin aangemaakt: ').$user->email);

        return self::SUCCESS;
    }
}
