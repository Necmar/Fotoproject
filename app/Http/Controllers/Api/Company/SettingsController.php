<?php

namespace App\Http\Controllers\Api\Company;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Company\UpdateCompanySettingsRequest;
use App\Http\Resources\CompanySettingsResource;
use App\Services\ActivityLogger;
use App\Services\CompanyStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function show(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $this->authorize('view', $company);

        return CompanySettingsResource::make($company)->response();
    }

    public function update(UpdateCompanySettingsRequest $request): JsonResponse
    {
        $company = $request->user()->company;
        $data = $request->validated();

        DB::transaction(function () use ($company, $data) {
            $company->update(['name' => $data['company_name']]);
            $company->settingsOrDefault()->update(Arr::except($data, ['company_name']));
        });

        $this->activity->log(ActivityAction::CompanySettingsUpdated, $company);

        return CompanySettingsResource::make($company->refresh())->response();
    }

    /** Numbers for the company dashboard. */
    public function stats(Request $request, CompanyStatsService $stats): JsonResponse
    {
        $company = $request->user()->company;
        $this->authorize('view', $company);

        return response()->json(['data' => $stats->forCompany($company)]);
    }
}
