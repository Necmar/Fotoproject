<?php

namespace App\Http\Controllers\Api\Account;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** The signed-in user. The React app calls this on start-up. */
    public function show(Request $request): JsonResponse
    {
        return UserResource::make($request->user()->load('company'))->response();
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email', 'locale']));

        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        $this->activity->log(ActivityAction::ProfileUpdated, $user, ['email_changed' => $emailChanged]);

        return UserResource::make($user->load('company'))->response();
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        // New remember token: "ingelogd blijven" cookies on other devices stop working.
        $user->forceFill(['password' => $request->string('password')->toString(), 'remember_token' => Str::random(60)])->save();

        // Invalidate other sessions of this user; keep the current one.
        $request->session()->regenerate();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        $this->activity->log(ActivityAction::PasswordChanged, $user);

        return response()->json(['message' => __('messages.account.password_updated')]);
    }
}
