<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActivityAction;
use App\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCompanyRequest;
use App\Http\Requests\Admin\UpdateCompanyRequest;
use App\Http\Resources\Admin\CompanyResource;
use App\Models\Company;
use App\Services\ActivityLogger;
use App\Services\CompanyService;
use App\Services\CompanyStatsService;
use App\Services\Mail\SafeMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function __construct(
        private readonly CompanyService $companies,
        private readonly CompanyStatsService $stats,
        private readonly ActivityLogger $activity,
        private readonly SafeMailer $mailer,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(CompanyStatus::class)],
            'sort' => ['nullable', Rule::in(['name', 'created_at', 'storage_bytes', 'images_processed_total'])],
            'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $query = $this->stats->withListStats(Company::query())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')).'%';
                $q->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhereHas('owner', fn ($o) => $o->where('email', 'like', $term)->orWhere('name', 'like', $term)));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        $sort = $request->string('sort', 'name')->toString();
        $query->orderBy($sort, $sort === 'name' ? 'asc' : 'desc');

        return CompanyResource::collection($query->paginate($request->integer('per_page', 25))->withQueryString());
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $company = $this->companies->create($data, sendMail: true, markVerified: (bool) ($data['mark_verified'] ?? false));

        $this->activity->log(ActivityAction::AdminCompanyCreated, $company, [
            'name' => $company->name,
            'email' => $company->owner?->email,
            'invited' => empty($data['password']),
        ]);

        return $this->withMailWarning(CompanyResource::make($this->reloadWithStats($company))->response()->setStatusCode(201));
    }

    public function show(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        return response()->json([
            'data' => CompanyResource::make($this->reloadWithStats($company)),
            'meta' => ['stats' => $this->stats->forCompany($company)],
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $company = $this->companies->update($company, $data);

        $this->activity->log(ActivityAction::AdminCompanyUpdated, $company, [
            'fields' => array_keys(Arr::except($data, ['password'])),
            'password_changed' => ! empty($data['password']),
        ]);

        return $this->withMailWarning(CompanyResource::make($this->reloadWithStats($company))->response());
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $this->authorize('delete', $company);

        // Deleting is irreversible: the client must repeat the exact company name.
        $request->validate(['confirm_name' => ['required', 'string', Rule::in([$company->name])]]);

        $this->companies->delete($company);

        return response()->json(['message' => __('messages.admin.company_deleted')]);
    }

    public function block(Request $request, Company $company): JsonResponse
    {
        $this->authorize('block', $company);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->companies->block($company, $data['reason'] ?? null);

        return CompanyResource::make($this->reloadWithStats($company))->response();
    }

    public function unblock(Company $company): JsonResponse
    {
        $this->authorize('block', $company);
        $this->companies->unblock($company);

        return CompanyResource::make($this->reloadWithStats($company))->response();
    }

    public function sendPasswordReset(Company $company): JsonResponse
    {
        $this->authorize('update', $company);
        $owner = $company->owner;
        abort_unless($owner !== null, 422, __('messages.admin.no_owner'));

        // Owners who never set a password get a fresh invitation instead.
        $invite = $owner->last_login_at === null && ! $owner->hasVerifiedEmail();
        if (! app(\App\Services\Mail\MailSettings::class)->isEnabled()) {
            throw new \App\Exceptions\DomainRuleException('mail_disabled');
        }

        $sent = $this->mailer->send(fn () => $invite ? $this->companies->sendInvitation($owner) : $this->companies->sendPasswordReset($owner), 'admin_password_reset');
        if (! $sent) {
            // Never the SMTP error itself: details are in the log.
            return response()->json(['message' => __('messages.admin.mail_send_failed'), 'code' => 'mail_failed'], 422);
        }

        $this->activity->log(ActivityAction::AdminPasswordResetSent, $company, ['email' => $owner->email]);

        return response()->json(['message' => __('messages.admin.password_reset_sent', ['email' => $owner->email])]);
    }

    public function purgeStorage(Company $company): JsonResponse
    {
        $this->authorize('delete', $company);
        $count = $this->companies->purgeStorage($company);

        $this->activity->log(ActivityAction::AdminStorageDeleted, $company, ['batches' => $count], company: $company);

        return response()->json(['message' => __('messages.admin.storage_deleted', ['count' => $count])]);
    }

    /** The account was saved, but its e-mail (invitation/verification) could not be sent. */
    private function withMailWarning(JsonResponse $response): JsonResponse
    {
        if ($this->companies->mailFailed) {
            $response->setData(array_replace((array) $response->getData(true), [
                'warning' => __('messages.admin.company_mail_failed'),
                'code' => 'mail_failed',
                'mail_failed' => true,
            ]));
        }

        return $response;
    }

    private function reloadWithStats(Company $company): Company
    {
        return $this->stats->withListStats(Company::query())->findOrFail($company->id);
    }
}
