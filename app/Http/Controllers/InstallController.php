<?php

namespace App\Http\Controllers;

use App\Services\System\Installer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Browser installer (no SSH needed). Runs without session or cookies, because
 * before installation there is no APP_KEY yet. Gone (404) once installed.
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer) {}

    public function show(): Response
    {
        abort_if($this->installer->isInstalled(), 404);

        return response()->view('install', ['errors' => [], 'input' => [], 'done' => null]);
    }

    public function store(Request $request): Response
    {
        abort_if($this->installer->isInstalled(), 404);

        $fields = ['db_database', 'db_username', 'db_password', 'name', 'email', 'password', 'password_confirmation', 'openai_key'];
        $data = $request->only($fields);
        $keep = array_intersect_key($data, array_flip(['db_database', 'db_username', 'name', 'email']));

        $validator = Validator::make($data, [
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/'],
            'db_username' => ['required', 'string', 'max:80'],
            'db_password' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'openai_key' => ['nullable', 'string', 'max:255', 'starts_with:sk-'],
        ], [], [
            'db_database' => 'databasenaam', 'db_username' => 'databasegebruiker', 'name' => 'naam',
            'email' => 'e-mailadres', 'password' => 'wachtwoord', 'openai_key' => 'OpenAI API-key',
        ]);

        if ($validator->fails()) {
            return response()->view('install', ['errors' => $validator->errors()->all(), 'input' => $keep, 'done' => null], 422);
        }

        $scheme = $request->isSecure() ? 'https' : 'http';

        try {
            $url = $this->installer->install($validator->validated(), $scheme.'://'.$request->getHttpHost());
        } catch (RuntimeException $e) {
            Log::warning('Installer failed', ['reason' => $e->getMessage()]);
            $message = match (true) {
                $e->getMessage() === 'db_connect' => 'Kan geen verbinding maken met de database. Controleer databasenaam, gebruiker en wachtwoord (zoals aangemaakt in Plesk > Databases).',
                $e->getMessage() === 'env_write' => 'Het instellingenbestand (.env) kon niet worden opgeslagen. Controleer de schrijfrechten van de projectmap.',
                str_starts_with($e->getMessage(), 'migrate') => 'De database kon niet worden ingericht. Probeer het opnieuw of kies een lege database.',
                default => 'Installatie mislukt. Probeer het opnieuw.',
            };

            return response()->view('install', ['errors' => [$message], 'input' => $keep, 'done' => null], 422);
        } catch (Throwable $e) {
            Log::error('Installer error', ['error' => $e->getMessage()]);

            return response()->view('install', ['errors' => ['Installatie mislukt. Probeer het opnieuw.'], 'input' => $keep, 'done' => null], 500);
        }

        return response()->view('install', ['errors' => [], 'input' => [], 'done' => $url.'/login']);
    }
}
