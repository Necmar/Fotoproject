<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $this->activity->log(ActivityAction::Login, user: $user);

        return UserResource::make($user->load('company'))->response();
    }

    public function destroy(Request $request): JsonResponse
    {
        if ($user = $request->user()) {
            $this->activity->log(ActivityAction::Logout, user: $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('messages.auth.logged_out')]);
    }
}
