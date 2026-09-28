<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production: only creates the Super Admin when SUPER_ADMIN_EMAIL and
     * SUPER_ADMIN_PASSWORD are set. Local: also a demo company.
     */
    public function run(CompanyService $companies): void
    {
        $email = config('bora.super_admin.email');
        $password = config('bora.super_admin.password');

        if ($email && $password && ! User::query()->where('role', UserRole::SuperAdmin)->exists()) {
            $admin = new User([
                'name' => config('bora.super_admin.name'),
                'email' => strtolower($email),
                'password' => $password,
            ]);
            $admin->role = UserRole::SuperAdmin;
            $admin->email_verified_at = now();
            $admin->save();

            $this->command?->info("Super Admin aangemaakt: {$admin->email}");
        }

        if (app()->environment('local') && ! User::query()->where('email', 'demo@example.com')->exists()) {
            $companies->create([
                'company_name' => 'Demo Autobedrijf',
                'owner_name' => 'Demo Eigenaar',
                'email' => 'demo@example.com',
                'password' => 'demo-wachtwoord-1',
            ], sendMail: false, markVerified: true);

            $this->command?->info('Demo-bedrijf: demo@example.com / demo-wachtwoord-1');
        }
    }
}
