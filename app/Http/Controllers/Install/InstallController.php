<?php

declare(strict_types=1);

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Support\Install\EnvWriter;
use App\Support\Install\Installer;
use App\Support\Install\Requirements;
use App\Support\Install\RoomBuilder;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * The first-run wizard (§16).
 *
 * The developer's path is a shell and one command. This is the path for
 * everybody else — a hotelier on shared hosting, a reseller setting up a
 * client — and both must end in the same state.
 *
 * Two things about it are load-bearing:
 *
 *  - **It is unauthenticated by definition**, because there is nobody to
 *    authenticate against yet. The token in `storage/install-token.txt`
 *    stands in: whoever can read a file on the server is exactly the
 *    person entitled to install onto it.
 *  - **Every step is resumable.** State lands in the installations row as
 *    each step completes, so a browser crash at step 6 resumes at step 6
 *    rather than at the beginning — or, worse, half way through with the
 *    first three steps applied twice.
 */
class InstallController extends Controller
{
    public function __construct(protected Installer $installer) {}

    /**
     * The token gate, shown before anything else.
     */
    public function index(Request $request): View|RedirectResponse
    {
        if ($this->installer->needsRepair()) {
            return view('install.repair', [
                'hasLock' => $this->installer->hasLock(),
                'hasRecord' => $this->installer->hasRecord(),
                'lockPath' => $this->installer->lockPath(),
            ]);
        }

        if ($request->session()->get('install_token_ok') === true) {
            return redirect('/install/'.$this->installer->currentStep());
        }

        return view('install.token', [
            // The path, never the value: printing the token on the page
            // that asks for it would make the gate decorative.
            'tokenPath' => $this->installer->tokenPath(),
            'created' => $this->installer->token() !== '',
        ]);
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        if (! $this->installer->tokenMatches($request->string('token')->toString())) {
            return back()->withErrors(['token' => __('install.token_wrong')]);
        }

        $request->session()->put('install_token_ok', true);

        return redirect('/install/'.$this->installer->currentStep());
    }

    public function step(Request $request, string $step): View|RedirectResponse
    {
        if (($guard = $this->guard($request, $step)) !== null) {
            return $guard;
        }

        return match ($step) {
            'language' => view('install.language', ['locales' => Localization::shipped()]),
            'requirements' => view('install.requirements', ['checks' => app(Requirements::class)->all()]),
            'database' => view('install.database', ['suggested' => database_path('database.sqlite')]),
            'hotel' => view('install.hotel', [
                'timezones' => \DateTimeZone::listIdentifiers(),
                'locales' => (array) config('doba.locales', ['en']),
            ]),
            'owner' => view('install.owner'),
            'rooms' => view('install.rooms', ['templates' => RoomBuilder::TEMPLATES]),
            'finish' => view('install.finish', [
                'cron' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
                'worker' => 'php artisan queue:work --tries=3',
            ]),
            default => redirect('/install'),
        };
    }

    public function submit(Request $request, string $step): RedirectResponse
    {
        if (($guard = $this->guard($request, $step)) !== null) {
            return $guard;
        }

        return match ($step) {
            'language' => $this->saveLanguage($request),
            'requirements' => $this->saveRequirements(),
            'database' => $this->saveDatabase($request),
            'hotel' => $this->saveHotel($request),
            'owner' => $this->saveOwner($request),
            'rooms' => $this->saveRooms($request),
            'finish' => $this->saveFinish($request),
            default => redirect('/install'),
        };
    }

    protected function saveLanguage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Every language the software ships, not only the ones in the
            // default env: a Ukrainian hotelier picking Ukrainian here is
            // the moment their site becomes Ukrainian, and a wizard that
            // only offers what an operator pre-configured has that
            // backwards.
            'locale' => ['required', Rule::in(Localization::shipped())],
        ]);

        $request->session()->put('install_locale', $validated['locale']);
        app()->setLocale($validated['locale']);

        // The chosen language leads the site's locale list, so it is the
        // default locale (§4) — staged for the finish step alongside the
        // hotel step's env, written once, together.
        $locales = array_values(array_unique(array_merge(
            [$validated['locale']],
            (array) config('doba.locales', ['en']),
        )));

        $this->stageEnv($request, [
            'APP_LOCALE' => $validated['locale'],
            'DOBA_LOCALES' => implode(',', $locales),
        ]);

        config(['doba.locales' => $locales, 'app.locale' => $validated['locale']]);

        // Recorded only if there is somewhere to record it: on a fresh
        // clone the database does not exist yet, and choosing a language
        // must not be the thing that fails.
        $this->remember('language', $validated['locale']);

        return redirect('/install/requirements');
    }

    protected function saveRequirements(): RedirectResponse
    {
        // Blocking, with no "continue anyway". The hotelier who clicks
        // past a missing `intl` is not the one who can diagnose the site
        // that half-works a month later.
        if (! app(Requirements::class)->satisfied()) {
            return back()->withErrors(['requirements' => __('install.requirements_unmet')]);
        }

        $this->remember('requirements');

        return redirect('/install/database');
    }

    protected function saveDatabase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'driver' => ['required', Rule::in(['sqlite', 'mysql'])],
            'database' => ['required_if:driver,mysql', 'nullable', 'string', 'max:255'],
            'host' => ['required_if:driver,mysql', 'nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['required_if:driver,mysql', 'nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'demo' => ['nullable', 'boolean'],
        ]);

        $connection = $this->connectionFor($validated);

        // Configured at runtime and tested BEFORE anything is written: a
        // .env pointing at a database that does not answer is an install
        // that cannot even show the error.
        config(['database.connections.install' => $connection]);
        DB::purge('install');

        try {
            DB::connection('install')->getPdo();
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['database' => __('install.database_failed', [
                'error' => $e->getMessage(),
            ])]);
        }

        try {
            EnvWriter::make()->write($this->envFor($validated));
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['database' => $e->getMessage()]);
        }

        config(['database.default' => 'install']);

        Artisan::call('migrate', ['--force' => true]);

        if ($request->boolean('demo')) {
            // Opt-in, and clearly labelled: a hotelier who wanted an empty
            // site and got a demo hotel called Alpenhof has to work out
            // which rooms are theirs.
            Artisan::call('db:seed', ['--force' => true]);
        }

        $this->remember('database');

        return redirect('/install/hotel');
    }

    protected function saveHotel(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:64'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:64'],
            'timezone' => ['required', 'timezone'],
            'currency' => ['required', 'string', 'size:3'],
            'checkin_from' => ['required', 'date_format:H:i'],
            'checkout_until' => ['required', 'date_format:H:i'],
        ]);

        Setting::put('general', 'name', $validated['name']);

        foreach (['email', 'phone', 'street', 'postal_code', 'city', 'country'] as $key) {
            Setting::put('contact', $key, $validated[$key] ?? null);
        }

        // Held for the finish step rather than written now.
        //
        // Deliberately NOT APP_NAME: the hotel's name is a setting read
        // through HotelSettings, and Laravel derives the session cookie
        // name from APP_NAME — writing it renamed the cookie mid-wizard
        // and logged the installer out of their own install.
        //
        // And deliberately not written yet: every .env write costs a
        // restart under `artisan serve`, and an install abandoned at step
        // 5 should not leave a half-configured .env behind. The database
        // step is the exception — migrations need it on disk immediately.
        $this->stageEnv($request, [
            'APP_TIMEZONE' => $validated['timezone'],
            'DOBA_CURRENCY' => strtoupper($validated['currency']),
            'DOBA_CHECKIN_FROM' => $validated['checkin_from'],
            'DOBA_CHECKOUT_UNTIL' => $validated['checkout_until'],
        ]);

        // Applied at runtime so the rest of the wizard already behaves as
        // the finished hotel will.
        config([
            'app.timezone' => $validated['timezone'],
            'doba.currency' => strtoupper($validated['currency']),
            'doba.checkin_from' => $validated['checkin_from'],
            'doba.checkout_until' => $validated['checkout_until'],
        ]);

        $this->remember('hotel');

        return redirect('/install/owner');
    }

    protected function saveOwner(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:254'],
            // Enforced, not suggested: this account can read every guest's
            // name, address and stay.
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()->uncompromised()],
        ]);

        User::updateOrCreate(
            ['email' => mb_strtolower($validated['email'])],
            [
                'name' => $validated['name'],
                'password' => Hash::make($validated['password']),
                'email_verified_at' => now(),
            ],
        );

        $this->remember('owner');

        return redirect('/install/rooms');
    }

    protected function saveRooms(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'template' => ['nullable', Rule::in(array_keys(RoomBuilder::TEMPLATES))],
            'rooms' => ['nullable', 'array', 'max:20'],
            'rooms.*.name' => ['nullable', 'string', 'max:255'],
            'rooms.*.units' => ['nullable', 'integer', 'min:1', 'max:500'],
            'rooms.*.occupancy' => ['nullable', 'integer', 'min:1', 'max:20'],
            'rooms.*.price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        $builder = new RoomBuilder;

        $created = $validated['template'] ?? null
            ? $builder->fromTemplate((string) $validated['template'])
            : $builder->fromRows($validated['rooms'] ?? []);

        if ($created === 0) {
            return back()->withInput()->withErrors(['rooms' => __('install.rooms_needed')]);
        }

        $this->remember('rooms');

        return redirect('/install/finish');
    }

    /**
     * Merge into the env staged for the finish step.
     *
     * Merge, never put: the language step and the hotel step both stage
     * keys, and whichever ran second used to silently discard the
     * first's.
     *
     * @param  array<string,string>  $values
     */
    protected function stageEnv(Request $request, array $values): void
    {
        $request->session()->put('install_env', array_merge(
            (array) $request->session()->get('install_env', []),
            $values,
        ));
    }

    protected function saveFinish(Request $request): RedirectResponse
    {
        $env = $request->session()->get('install_env', []);

        if (is_array($env) && $env !== []) {
            EnvWriter::make()->write($env);
        }

        Artisan::call('storage:link');
        Artisan::call('optimize:clear');

        $this->installer->finish();

        // Signed straight in: the owner account was created three steps
        // ago and asking them to type it again immediately is a password
        // reset waiting to happen.
        $owner = User::query()->oldest('id')->first();

        if ($owner !== null) {
            Auth::login($owner);
        }

        return redirect('/admin/front-desk');
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    protected function connectionFor(array $input): array
    {
        if ($input['driver'] === 'sqlite') {
            $path = database_path('database.sqlite');

            if (! is_file($path)) {
                touch($path);
            }

            return [
                'driver' => 'sqlite',
                'database' => $path,
                'prefix' => '',
                'foreign_key_constraints' => true,
                // §6: the transaction mode the booking lock depends on.
                'journal_mode' => 'WAL',
                'transaction_mode' => 'IMMEDIATE',
                'busy_timeout' => 5000,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => $input['host'],
            'port' => (string) ($input['port'] ?? 3306),
            'database' => $input['database'],
            'username' => $input['username'],
            'password' => (string) ($input['password'] ?? ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,string|int|null>
     */
    protected function envFor(array $input): array
    {
        if ($input['driver'] === 'sqlite') {
            return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => database_path('database.sqlite')];
        }

        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $input['host'],
            'DB_PORT' => (string) ($input['port'] ?? 3306),
            'DB_DATABASE' => (string) $input['database'],
            'DB_USERNAME' => (string) $input['username'],
            'DB_PASSWORD' => (string) ($input['password'] ?? ''),
        ];
    }

    protected function remember(string $step, ?string $locale = null): void
    {
        try {
            $this->installer->markComplete($step, $locale);
        } catch (Throwable) {
            // Before the database step there may be no database. The
            // session carries the wizard until there is one.
        }
    }

    protected function guard(Request $request, string $step): ?RedirectResponse
    {
        if ($request->session()->get('install_token_ok') !== true) {
            return redirect('/install');
        }

        if (! in_array($step, Installer::STEPS, true)) {
            return redirect('/install');
        }

        // Language and requirements run before there is a database to
        // record progress in, so the session is what orders them.
        if (in_array($step, ['language', 'requirements'], true)) {
            return null;
        }

        if (! $this->installer->isStepAvailable($step)) {
            // Step 5 writes an owner into a database step 3 creates.
            return redirect('/install/'.$this->installer->currentStep());
        }

        return null;
    }
}
