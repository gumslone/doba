<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Routing\AdminLocale;
use App\Support\Routing\Localization;
use PHPUnit\Framework\Assert;

/**
 * The admin in the language of the person reading it (§12).
 */
beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    config()->set('app.locale', 'en');
    $this->admin = User::factory()->create();
});

it('ships the admin and the wizard in every language the guest side speaks', function (): void {
    $flatten = fn (array $a): array => array_keys($a);

    foreach (['admin', 'install'] as $file) {
        $reference = include lang_path("en/{$file}.php");

        foreach (Localization::shipped() as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $path = lang_path("{$locale}/{$file}.php");
            Assert::assertFileExists($path);
            $translated = include $path;

            Assert::assertSame([], array_values(array_diff($flatten($reference), $flatten($translated))), "lang/{$locale}/{$file}.php is missing keys");
            Assert::assertSame([], array_values(array_diff($flatten($translated), $flatten($reference))), "lang/{$locale}/{$file}.php has keys English does not");

            foreach ($reference as $key => $english) {
                // Every :placeholder survives translation, or a sentence
                // reaches a receptionist with ":amount" in it.
                preg_match_all('/:[a-z_]+/', $english, $expected);

                foreach (array_unique($expected[0]) as $placeholder) {
                    // ":countth stay" is :count with "th" glued on — English
                    // does that, and so may a translation (":counte séjour").
                    $stem = preg_replace('/(th|st|nd|rd|e|s)$/', '', $placeholder);

                    Assert::assertTrue(
                        str_contains($translated[$key], $placeholder) || (strlen((string) $stem) >= 3 && str_contains($translated[$key], (string) $stem)),
                        "{$locale}/{$file}.{$key} lost {$placeholder}",
                    );
                }

                // Plural strings stay plural strings. Slavic languages may
                // split the last form in two; nothing may collapse.
                $pipes = substr_count($english, '|');
                $got = substr_count($translated[$key], '|');
                Assert::assertTrue($pipes === 0 ? $got === 0 : ($got >= $pipes && $got <= $pipes + 1), "{$locale}/{$file}.{$key} has {$got} plural separators, English has {$pipes}");
            }
        }
    }
});

it('offers each language under its own name', function (): void {
    expect(AdminLocale::available())->toMatchArray([
        'en' => 'English', 'de' => 'Deutsch', 'pl' => 'Polski', 'uk' => 'Українська', 'fr' => 'Français', 'nl' => 'Nederlands',
    ]);
});

it('reads in English until somebody says otherwise, then in their language for good', function (): void {
    $this->actingAs($this->admin)->get('/admin/front-desk')->assertOk()->assertSee('Front desk');

    $this->actingAs($this->admin)->post('/admin/security/locale', ['locale' => 'de'])->assertRedirect('/admin/security');

    expect($this->admin->fresh()->locale)->toBe('de');

    $page = $this->actingAs($this->admin->fresh())->get('/admin/front-desk')->assertOk();
    $page->assertSee('lang="de"', false)->assertSee(__('admin.front_desk', [], 'de'))->assertDontSee('>Front desk<', false);

    // A colleague is not dragged along.
    $colleague = User::factory()->create();
    $this->flushSession();
    $this->actingAs($colleague)->get('/admin/front-desk')->assertSee('Front desk');

    $this->actingAs($this->admin)->post('/admin/security/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

it('follows the hotel\'s main language when the admin speaks it, and lets the login screen choose', function (): void {
    config()->set('app.locale', 'de');
    config()->set('doba.locales', ['de', 'en']);

    $this->get('/admin/login')->assertOk()->assertSee('lang="de"', false)->assertSee('Polski')->assertSee('Українська');

    // A link on the login screen, remembered through the redirect into the panel.
    $this->get('/admin/login?lang=pl')->assertOk()->assertSee('lang="pl"', false);
    $this->get('/admin/login')->assertSee('lang="pl"', false);
});

it('keeps a demo visitor\'s language in their session, never on the shared account', function (): void {
    config()->set('doba.demo.enabled', true);

    $this->actingAs($this->admin)->post('/admin/security/locale', ['locale' => 'uk'])->assertSessionMissing('demo_blocked');

    expect($this->admin->fresh()->locale)->toBeNull();
    $this->actingAs($this->admin)->get('/admin/front-desk')->assertSee('lang="uk"', false);
});
