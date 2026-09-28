<?php

namespace App\Services;

use App\Enums\ActivityAction;
use App\Enums\CompanyStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use App\Notifications\CompanyInvitation;
use App\Services\Storage\BatchDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Business logic for companies and their single owner account.
 * Used by the Super Admin controllers and by public registration.
 */
class CompanyService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly BatchDeletionService $batches,
    ) {}

    /**
     * Create a company, its settings row and its owner.
     *
     * When no password is given the owner receives an invitation e-mail with a
     * link to choose one (this also verifies the e-mail address). Otherwise a
     * regular verification e-mail is sent.
     *
     * @param  array{company_name: string, owner_name: string, email: string, password?: ?string, locale?: ?string}  $data
     */
    public function create(array $data, bool $sendMail = true, bool $markVerified = false): Company
    {
        $hasPassword = ! empty($data['password']);

        [$company, $owner] = DB::transaction(function () use ($data, $hasPassword, $markVerified) {
            $company = Company::query()->create([
                'name' => $data['company_name'],
                'status' => CompanyStatus::Active,
            ]);

            $company->settings()->create(array_replace(CompanySetting::defaults(), [
                'filename_prefix' => config('bora.company_defaults.filename_prefix'),
            ]));

            $owner = new User([
                'name' => $data['owner_name'],
                'email' => Str::lower($data['email']),
                'password' => $hasPassword ? $data['password'] : Str::password(40),
                'locale' => $data['locale'] ?? config('app.locale'),
            ]);
            $owner->role = UserRole::CompanyOwner;
            $owner->company()->associate($company);
            $owner->email_verified_at = $markVerified ? now() : null;
            $owner->save();

            return [$company, $owner];
        });

        if ($sendMail) {
            if ($hasPassword) {
                if (! $owner->hasVerifiedEmail()) {
                    $owner->sendEmailVerificationNotification();
                }
            } else {
                $this->sendInvitation($owner);
            }
        }

        return $company->load('owner', 'settings');
    }

    /** @param array<string, mixed> $data */
    public function update(Company $company, array $data): Company
    {
        DB::transaction(function () use ($company, $data) {
            $company->fill(array_filter([
                'name' => $data['company_name'] ?? null,
            ], fn ($v) => $v !== null))->save();

            $owner = $company->owner;

            if ($owner) {
                if (isset($data['owner_name'])) {
                    $owner->name = $data['owner_name'];
                }

                if (isset($data['email']) && Str::lower($data['email']) !== $owner->email) {
                    $owner->email = Str::lower($data['email']);
                    $owner->email_verified_at = null;
                }

                if (! empty($data['password'])) {
                    $owner->password = $data['password'];
                }

                if (isset($data['locale'])) {
                    $owner->locale = $data['locale'];
                }

                $emailChanged = $owner->isDirty('email');
                $owner->save();

                if ($emailChanged) {
                    $owner->sendEmailVerificationNotification();
                }
            }
        });

        return $company->refresh()->load('owner', 'settings');
    }

    public function block(Company $company, ?string $reason = null): Company
    {
        $company->forceFill([
            'status' => CompanyStatus::Blocked,
            'blocked_at' => now(),
            'blocked_reason' => $reason,
        ])->save();

        // Kill active sessions so the block takes effect immediately.
        DB::table('sessions')->whereIn('user_id', $company->users()->pluck('id'))->delete();

        $this->activity->log(ActivityAction::AdminCompanyBlocked, $company, ['reason' => $reason], company: $company);

        return $company;
    }

    public function unblock(Company $company): Company
    {
        $company->forceFill([
            'status' => CompanyStatus::Active,
            'blocked_at' => null,
            'blocked_reason' => null,
        ])->save();

        $this->activity->log(ActivityAction::AdminCompanyUnblocked, $company, company: $company);

        return $company;
    }

    /** Delete a company including every stored file. */
    public function delete(Company $company): void
    {
        $name = $company->name;
        $id = $company->id;

        $this->purgeStorage($company);

        Storage::disk(config('bora.disk'))->deleteDirectory("companies/{$id}");

        DB::table('sessions')->whereIn('user_id', $company->users()->pluck('id'))->delete();
        $company->delete();

        $this->activity->log(ActivityAction::AdminCompanyDeleted, null, ['company_id' => $id, 'company_name' => $name]);
    }

    /** Remove all batches and files of a company but keep the account and its statistics. */
    public function purgeStorage(Company $company): int
    {
        $deleted = 0;

        $company->batches()->each(function ($batch) use (&$deleted) {
            $this->batches->delete($batch, logActivity: false);
            $deleted++;
        });

        $company->forceFill(['storage_bytes' => 0])->save();

        return $deleted;
    }

    /** Send a normal password reset link (Super Admin action or "forgot password"). */
    public function sendPasswordReset(User $user): string
    {
        return Password::broker('users')->sendResetLink(['email' => $user->email]);
    }

    /** Invitation with a longer-lived "choose your password" link. */
    public function sendInvitation(User $user): void
    {
        $token = Password::broker('invites')->createToken($user);
        $user->notify(new CompanyInvitation($token));
    }
}
