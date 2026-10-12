<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrganizationType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Provisions a tenant together with its first administrator. This is the only
 * way to create an organization in Phase 1; there is intentionally no public
 * self-registration endpoint.
 */
final class CreateOrganizationCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'rebuild:organization:create
        {name : Display name of the organization}
        {--type= : One of: ngo, un_agency, government, community_based, private_contractor, donor}
        {--slug= : URL-safe identifier (derived from the name when omitted)}
        {--admin-name= : Full name of the first administrator}
        {--admin-email= : Email of the first administrator}';

    /**
     * @var string
     */
    protected $description = 'Create an organization (tenant) and its first administrator account';

    public function handle(TenantContext $tenant): int
    {
        $name = (string) $this->argument('name');
        $type = (string) ($this->option('type') ?? $this->choice('Organization type', OrganizationType::values()));
        $slug = (string) ($this->option('slug') ?? Str::slug($name));
        $adminName = (string) ($this->option('admin-name') ?? $this->ask('Administrator full name'));
        $adminEmail = mb_strtolower(trim((string) ($this->option('admin-email') ?? $this->ask('Administrator email'))));
        $password = (string) $this->secret('Administrator password (leave empty to generate one)');
        $generated = $password === '';

        if ($generated) {
            $password = Str::password(20);
        }

        $validator = Validator::make(
            compact('name', 'type', 'slug', 'adminName', 'adminEmail', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', Rule::enum(OrganizationType::class)],
                'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('organizations', 'slug')],
                'adminName' => ['required', 'string', 'max:255'],
                'adminEmail' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
                'password' => ['required', Password::min(12)->letters()->mixedCase()->numbers()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        [$organization, $admin] = DB::transaction(function () use ($tenant, $name, $type, $slug, $adminName, $adminEmail, $password): array {
            $organization = Organization::query()->create([
                'name' => $name,
                'type' => OrganizationType::from($type),
                'slug' => $slug,
            ]);

            $admin = $tenant->runAs($organization, static function () use ($adminName, $adminEmail, $password): User {
                $admin = new User([
                    'name' => $adminName,
                    'email' => $adminEmail,
                    'password' => $password,
                ]);
                $admin->role = UserRole::Admin;
                $admin->email_verified_at = now();
                $admin->save();

                return $admin;
            });

            return [$organization, $admin];
        });

        $this->components->info("Organization [{$organization->name}] created.");
        $this->components->twoColumnDetail('Organization ID', $organization->id);
        $this->components->twoColumnDetail('Administrator', "{$admin->name} <{$admin->email}>");

        if ($generated) {
            $this->components->warn('Generated administrator password (shown once, store it securely):');
            $this->line($password);
        }

        return self::SUCCESS;
    }
}
