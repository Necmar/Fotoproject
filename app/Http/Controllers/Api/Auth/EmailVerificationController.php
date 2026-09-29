<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\FrontendUrl;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Target of the link in the verification e-mail (signed URL). Works without
     * being signed in, so the link also works on another device.
     */
    public function verify(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->to(FrontendUrl::to('/login', ['verified' => 'invalid']));
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $this->activity->log(ActivityAction::EmailVerified, $user, user: $user);
        }

        $target = $request->user()?->is($user) ? '/' : '/login';

        return redirect()->to(FrontendUrl::to($target, ['verified' => '1']));
    }

    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => __('messages.auth.already_verified')]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => __('messages.auth.verification_sent')]);
    }
}
