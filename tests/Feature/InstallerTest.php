<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\System\Installer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Browser installer for hosting without SSH. */
class InstallerTest extends TestCase
{
    use RefreshDatabase;

    private string $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->env = sys_get_temp_dir().'/bora-install-'.uniqid().'.env';
    }

    protected function tearDown(): void
    {
        @unlink($this->env);
        parent::tearDown();
    }

    /** Installer that uses the test database instead of MySQL. */
    private function fakeInstaller(bool $dbWorks = true): void
    {
        $this->app->instance(Installer::class, new class($this->env, $dbWorks) extends Installer
        {
            public function __construct(string $env, private bool $dbWorks)
            {
                parent::__construct($env);
            }

            protected function useConnection(array $connection): void
            {
                // Keep the test database.
            }

            protected function testConnection(array $connection): void
            {
                if (! $this->dbWorks) {
                    throw new \RuntimeException('db_connect');
                }
            }
        });
    }

    private function form(array $override = []): array
    {
        return array_replace([
            'db_database' => 'bora', 'db_username' => 'bora_user', 'db_password' => 'p@ss "w$rd"',
            'name' => 'Beheer', 'email' => 'Beheer@Example.com', 'password' => 'sterk-wachtwoord-1', 'password_confirmation' => 'sterk-wachtwoord-1',
            'openai_key' => '',
        ], $override);
    }

    public function test_everything_redirects_to_the_installer_until_installed(): void
    {
        $this->fakeInstaller();

        $this->get('/')->assertRedirect('/install');
        $this->get('/login')->assertRedirect('/install');
        $this->getJson('/api/meta')->assertStatus(503)->assertJsonPath('code', 'not_installed');
        $this->get('/install')->assertOk()->assertSee('Bora Foto installeren');
    }

    public function test_install_creates_the_super_admin_and_writes_env(): void
    {
        $this->fakeInstaller();

        $this->post('/install', $this->form())->assertOk()->assertSee('De installatie is gelukt');

        $admin = User::query()->where('email', 'beheer@example.com')->firstOrFail();
        $this->assertSame(UserRole::SuperAdmin, $admin->role);
        $this->assertTrue(Hash::check('sterk-wachtwoord-1', $admin->password));

        $env = file_get_contents($this->env);
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/=]{44}$/m', $env);
        $this->assertStringContainsString('DB_DATABASE=bora', $env);
        $this->assertStringContainsString('DB_PASSWORD="p@ss \"w\$rd\""', $env);
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('MAIL_MAILER=log', $env);

        // The value round-trips through the .env parser.
        $parsed = \Dotenv\Dotenv::parse($env);
        $this->assertSame('p@ss "w$rd"', $parsed['DB_PASSWORD']);

        // Installed: the installer is gone and the app no longer redirects.
        $this->get('/install')->assertNotFound();
        $this->post('/install', $this->form())->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_wrong_database_details_are_explained_and_nothing_is_written(): void
    {
        $this->fakeInstaller(dbWorks: false);

        $this->post('/install', $this->form())->assertStatus(422)->assertSee('Kan geen verbinding maken met de database');

        $this->assertFileDoesNotExist($this->env);
        $this->assertSame(0, User::query()->count());
    }

    public function test_validation_errors_keep_input_but_never_passwords(): void
    {
        $this->fakeInstaller();

        $this->post('/install', $this->form(['password_confirmation' => 'anders-1234567', 'db_database' => 'bad name!']))
            ->assertStatus(422)
            ->assertSee('bora_user')
            ->assertDontSee('p@ss')
            ->assertDontSee('sterk-wachtwoord-1');

        $this->assertFileDoesNotExist($this->env);
    }
}
