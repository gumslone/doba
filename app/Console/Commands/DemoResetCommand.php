<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Demo\Demo;
use App\Support\Hotel\HotelSettings;
use App\Support\Install\Installer;
use App\Support\Version;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoStaySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild the public demo from nothing (§22).
 *
 * Drops every table. That is the point on a demo and a catastrophe
 * anywhere else, so it runs only where DOBA_DEMO is on — there is no
 * --force, because the one time somebody reaches for it is the one time
 * it should have said no.
 */
class DemoResetCommand extends Command
{
    protected $signature = 'doba:demo:reset';

    protected $description = 'Wipe and reseed a public demo install (refuses unless DOBA_DEMO=true)';

    public function handle(Installer $installer): int
    {
        if (! Demo::enabled()) {
            $this->error('This is not a demo install (DOBA_DEMO is not true). Nothing was touched.');

            return self::FAILURE;
        }

        $this->components->task('Dropping and migrating', fn () => Artisan::call('migrate:fresh', ['--force' => true]) === 0);
        $this->components->task('Seeding the hotel', fn () => Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]) === 0);
        $this->components->task('Seeding guests and stays', fn () => Artisan::call('db:seed', ['--class' => DemoStaySeeder::class, '--force' => true]) === 0);

        // migrate:fresh took the installation record with it. Both markers
        // go back, or the next visitor is sent to the wizard.
        DB::table('installations')->insert([
            'steps_completed' => json_encode(Installer::STEPS),
            'locale' => 'en',
            'version' => Version::current(),
            'installed_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        if (! $installer->hasLock()) {
            file_put_contents($installer->lockPath(), (string) CarbonImmutable::now());
        }

        if (is_file($installer->tokenPath())) {
            @unlink($installer->tokenPath());
        }

        HotelSettings::flush();
        Artisan::call('doba:sitemap');

        $this->info('Demo rebuilt. Admin: '.config('doba.admin.email').' / '.config('doba.admin.password'));

        return self::SUCCESS;
    }
}
