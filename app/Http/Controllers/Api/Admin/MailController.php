<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use App\Services\Mail\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

/** Super Admin > Systeem > E-mail: switch e-mail on/off and set the SMTP server. */
class MailController extends Controller
{
    public function __construct(
        private readonly MailSettings $mail,
        private readonly ActivityLogger $activity,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->mail->forAdmin()]);
    }

    /**
     * SMTP fields are only required when the admin actually configures SMTP:
     * - a host is filled in: port and sender are required too;
     * - "on" without a host is fine when e-mail already works without one
     *   (automatic mode that is on, or a real MAIL_MAILER in .env). Saving the
     *   untouched card in automatic mode keeps it automatic.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate(['mail_enabled' => ['required', 'boolean']]);

        $on = $request->boolean('mail_enabled');
        $smtp = $request->filled('smtp_host');
        $keepAutomatic = $on && ! $smtp && $this->mail->toggle() === null && $this->mail->isEnabled();
        $needsSmtp = $on && ! $keepAutomatic && ($smtp || ! $this->mail->envMailerUsable());

        $data = $request->validate([
            'mail_enabled' => ['required', 'boolean'],
            'smtp_host' => ['nullable', Rule::requiredIf($needsSmtp), 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'smtp_port' => ['nullable', Rule::requiredIf($needsSmtp && $smtp), 'integer', 'between:1,65535'],
            'smtp_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'clear_password' => ['sometimes', 'boolean'],
            'mail_from_address' => ['nullable', Rule::requiredIf($needsSmtp), 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:120'],
        ]);

        if ($keepAutomatic) {
            unset($data['mail_enabled']);
        }

        $this->mail->update($data);

        // Never log the password, only what changed.
        $this->activity->log(ActivityAction::AdminSettingsUpdated, properties: [
            'mail_enabled' => $keepAutomatic ? 'automatic' : (bool) $data['mail_enabled'],
            'smtp_host' => $data['smtp_host'] ?? null,
            'password_changed' => filled($data['smtp_password'] ?? null),
        ]);

        return $this->show();
    }

    /** Sends a test message to the signed-in Super Admin, right away (not via the queue). */
    public function test(Request $request): JsonResponse
    {
        if (! $this->mail->isEnabled()) {
            return response()->json(['message' => __('messages.admin.mail_test_disabled')], 422);
        }

        $this->mail->apply();
        $to = $request->user()->email;

        try {
            Mail::raw(__('messages.admin.mail_test_body', ['app' => config('app.name')]), function ($message) use ($to) {
                $message->to($to)->subject(__('messages.admin.mail_test_subject', ['app' => config('app.name')]));
            });
        } catch (Throwable $e) {
            Log::warning('SMTP test failed', ['error' => $e->getMessage()]);

            // The SMTP server's own error helps the admin (e.g. "authentication failed"); it never contains the password.
            return response()->json(['message' => __('messages.admin.mail_test_failed', ['error' => mb_substr($e->getMessage(), 0, 300)])], 422);
        }

        return response()->json(['message' => __('messages.admin.mail_test_sent', ['email' => $to])]);
    }
}
