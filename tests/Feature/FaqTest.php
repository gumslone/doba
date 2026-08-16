<?php

declare(strict_types=1);

use App\Models\Faq;

function makeFaq(int $order, array $translations, bool $published = true): Faq
{
    $faq = Faq::create(['sort_order' => $order, 'is_published' => $published]);

    foreach ($translations as $locale => [$question, $answer]) {
        $faq->translations()->create([
            'locale' => $locale,
            'question' => $question,
            'answer' => $answer,
        ]);
    }

    return $faq;
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
});

it('renders published FAQs on the home page with matching FAQPage JSON-LD', function (): void {
    makeFaq(1, [
        'en' => ['Is parking available?', 'Yes, free of charge.'],
        'de' => ['Gibt es Parkplätze?', 'Ja, kostenlos.'],
    ]);

    $html = $this->get('/de')->assertOk()->getContent();

    expect($html)->toContain('Gibt es Parkplätze?');

    $schema = collect(jsonLdBlocks($html))->firstWhere('@type', 'FAQPage');

    expect($schema)->not->toBeNull()
        ->and($schema['mainEntity'][0]['name'])->toBe('Gibt es Parkplätze?')
        ->and($schema['mainEntity'][0]['acceptedAnswer']['text'])->toBe('Ja, kostenlos.');
});

it('emits no FAQPage node when there are no FAQs', function (): void {
    $html = $this->get('/en')->assertOk()->getContent();

    expect(collect(jsonLdBlocks($html))->firstWhere('@type', 'FAQPage'))->toBeNull();
});

it('hides unpublished FAQs from both the page and the markup', function (): void {
    makeFaq(1, ['en' => ['Visible?', 'Yes.']]);
    makeFaq(2, ['en' => ['Hidden?', 'Never.']], published: false);

    $html = $this->get('/en')->assertOk()->getContent();

    expect($html)->toContain('Visible?')
        ->and($html)->not->toContain('Hidden?');
});

it('falls back to the fallback locale for an untranslated FAQ', function (): void {
    // Prose falls back (unlike slugs): a German guest reading an English
    // answer beats a question silently missing from the German page.
    makeFaq(1, ['en' => ['Late check-in?', 'Until 22:00.']]);

    $html = $this->get('/de')->assertOk()->getContent();

    expect($html)->toContain('Late check-in?');
});

it('orders FAQs by sort_order', function (): void {
    makeFaq(2, ['en' => ['Second question?', 'B.']]);
    makeFaq(1, ['en' => ['First question?', 'A.']]);

    $schema = collect(jsonLdBlocks($this->get('/en')->assertOk()->getContent()))
        ->firstWhere('@type', 'FAQPage');

    expect($schema['mainEntity'][0]['name'])->toBe('First question?')
        ->and($schema['mainEntity'][1]['name'])->toBe('Second question?');
});
