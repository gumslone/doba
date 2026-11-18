<?php

declare(strict_types=1);

use App\Support\Demo\Demo;
use App\Support\Security\DefaultAdmin;
use Illuminate\Database\Migrations\Migration;

/**
 * Installs made with the wizard's "demo content" option kept the seeded
 * `admin@example.com` / `password` account next to the owner's own. This
 * closes it on every install that has another way in. A public demo keeps
 * its published login on purpose, and a developer's seeded copy — where
 * it is the only account — is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Demo::enabled()) {
            return;
        }

        DefaultAdmin::close();
    }

    public function down(): void
    {
        // A closed door stays closed.
    }
};
