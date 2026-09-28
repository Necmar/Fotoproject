<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\ActivityLogger;
use App\Services\CompanyService;
use App\Services\SystemSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** Public company registration. Disabled unless the Super Admin enables it. */
class RegisterController extends Controller
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly SystemSettings $settings,
        private readonly ActivityLogger $activity,
    ) {}

    public function store(RegisterRequest $request): JsonResponse
    {
        abort_unless($this->settings->registrationEnabled(), 403, __('messages.auth.registration_disabled'));

        $company = $this->companies->create($request->validated());
        $owner = $company->owner;

        $this->activity->log(ActivityAction::CompanyRegistered, $company, user: $owner, company: $company);

        Auth::guard('web')->login($owner);
        $request->session()->regenerate();

        return UserResource::make($owner->load('company'))->response()->setStatusCode(201);
    }
}
