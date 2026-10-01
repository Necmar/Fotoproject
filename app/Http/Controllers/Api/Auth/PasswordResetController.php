<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Send a reset link. Always answers with the same message so the endpoint
     * cannot be used to find out which e-mail addresses exist.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:255']]);

        if (! app(\App\Services\Mail\MailSettings::class)->isEnabled()) {
            throw new \App\Exceptions\DomainRuleException('mail_disabled');
        }

        // Same answer even when sending fails (logged), so this cannot reveal which addresses exist.
        app(\App\Services\Mail\SafeMailer::class)->send(
            fn () => Password::broker('users')->sendResetLink(['email' => Str::lower($request->string('email'))]),
            'forgot_password',
        );

        return response()->json(['message' => __('passwords.sent_generic')]);
    }

    /** Reset (or, for invitations, set) the password. Proves e-mail ownership, so it also verifies. */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $broker = $request->boolean('invite') ? 'invites' : 'users';

        $status = Password::broker($broker)->reset(
            [
                'email' => Str::lower($request->string('email')),
                'password' => $request->string('password')->toString(),
                'token' => $request->string('token')->toString(),
            ],
            function (User $user, string $password) use ($request) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                // Sign out other sessions of this user (never the session making this request).
                DB::table('sessions')->where('user_id', $user->id)
                    ->when($request->hasSession(), fn ($q) => $q->where('id', '!=', $request->session()->getId()))
                    ->delete();

                event(new PasswordReset($user));
                $this->activity->log(ActivityAction::PasswordReset, $user, user: $user);
            },
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }
}
