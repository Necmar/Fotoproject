<?php

namespace App\Http\Controllers\Api\Company;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanySettingsResource;
use App\Services\LogoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogoController extends Controller
{
    public function __construct(private readonly LogoService $logos) {}

    /** Only the own company's logo; there is no route that takes another company's id. */
    public function show(Request $request): StreamedResponse
    {
        $company = $request->user()->company;
        $disk = Storage::disk(config('bora.disk'));
        abort_if(! $company->logo_path || ! $disk->exists($company->logo_path), 404);

        return $disk->response($company->logo_path, null, [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $this->authorize('update', $company);
        $request->validate(['logo' => ['required', 'file', 'max:'.(LogoService::MAX_MB * 1024)]]);

        $this->logos->store($company, $request->file('logo'));

        return CompanySettingsResource::make($company->refresh())->response();
    }

    public function destroy(Request $request): JsonResponse
    {
        $company = $request->user()->company;
        $this->authorize('update', $company);
        $this->logos->delete($company);

        return CompanySettingsResource::make($company->refresh())->response();
    }
}
