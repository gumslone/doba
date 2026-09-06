<?php

declare(strict_types=1);

use App\Models\Amenity;
use App\Models\Availability;
use App\Models\RoomType;
use App\Models\User;

/**
 * The categories the hotel sells, editable at last (§12).
 */
function roomTypePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'is_active' => '1',
        'base_occupancy' => 2, 'max_occupancy' => 3, 'max_adults' => 3, 'max_children' => 1,
        'default_rate' => 14500, 'total_units' => 4, 'size_sqm' => 28, 'bed_setup' => '1 double, 1 sofa',
        'translations' => [
            'en' => ['name' => 'Garden Suite', 'short_description' => 'Opens onto the garden.', 'description' => '<p>Quiet.</p><script>alert(1)</script>'],
            'de' => ['name' => 'Gartensuite', 'slug' => 'gartensuite'],
        ],
    ], $overrides);
}

beforeEach(function (): void {
    config()->set('doba.locales', ['en', 'de']);
    $this->admin = User::factory()->create();
});

it('keeps room types behind the admin session', function (): void {
    $this->get('/admin/room-types')->assertRedirect('/admin/login');
    $this->post('/admin/room-types', roomTypePayload())->assertRedirect('/admin/login');
});

it('creates a type that is bookable the moment it exists', function (): void {
    $this->actingAs($this->admin)->post('/admin/room-types', roomTypePayload())->assertRedirect();

    $type = RoomType::sole();

    expect($type->code)->toBe('GARDEN_SUITE')
        ->and($type->max_occupancy)->toBe(3)
        ->and($type->default_rate)->toBe(14500)
        ->and($type->translations->count())->toBe(2)
        ->and($type->t('name', 'de'))->toBe('Gartensuite')
        // Slug from the name where none was typed; kept where one was.
        ->and($type->translations->firstWhere('locale', 'en')?->slug)->toBe('garden-suite')
        ->and($type->translations->firstWhere('locale', 'de')?->slug)->toBe('gartensuite')
        // Sanitised on write like every other editor field.
        ->and((string) $type->t('description', 'en'))->not->toContain('<script')
        // And the calendar is live: availability:extend ran.
        ->and(Availability::query()->where('room_type_id', $type->id)->count())->toBeGreaterThan(30)
        ->and(Availability::query()->where('room_type_id', $type->id)->first()?->allotment)->toBe(4);
});

it('never collides on the code when two types share a name', function (): void {
    $this->actingAs($this->admin)->post('/admin/room-types', roomTypePayload());
    $this->actingAs($this->admin)->post('/admin/room-types', roomTypePayload());

    expect(RoomType::query()->pluck('code')->all())->toBe(['GARDEN_SUITE', 'GARDEN_SUITE_2']);
});

it('edits occupancy, rate, texts and amenities, and drops a language without a name', function (): void {
    $this->actingAs($this->admin)->post('/admin/room-types', roomTypePayload());
    $type = RoomType::sole();

    $amenity = Amenity::create(['code' => 'balcony', 'icon' => 'sun', 'category' => 'view', 'sort_order' => 1]);
    $amenity->translations()->create(['locale' => 'en', 'name' => 'Balcony']);

    $this->actingAs($this->admin)->put('/admin/room-types/'.$type->id, roomTypePayload([
        'max_occupancy' => 4, 'max_adults' => 4, 'default_rate' => 16000,
        'amenities' => [$amenity->id],
        'translations' => ['de' => ['name' => '']],   // German dropped
    ]))->assertRedirect();

    $type->refresh()->load('translations', 'amenities');

    expect($type->max_occupancy)->toBe(4)
        ->and($type->default_rate)->toBe(16000)
        ->and($type->amenities->pluck('id')->all())->toBe([$amenity->id])
        // A language with no name is simply not served (§4).
        ->and($type->translations->pluck('locale')->all())->toBe(['en']);
});

it('refuses a default-language type with no name, and a max below the base', function (): void {
    $this->actingAs($this->admin)->post('/admin/room-types', roomTypePayload([
        'translations' => ['en' => ['name' => '']],
    ]))->assertSessionHasErrors('translations.en.name');

    $this->actingAs($this->admin)->post('/admin/room-types', roomTypePayload([
        'base_occupancy' => 3, 'max_occupancy' => 2,
    ]))->assertSessionHasErrors('max_occupancy');

    expect(RoomType::query()->count())->toBe(0);
});

it('takes a hidden type off the site without deleting its history', function (): void {
    $this->actingAs($this->admin)->post('/admin/room-types', roomTypePayload());
    $type = RoomType::sole();

    $this->get('/en/rooms/garden-suite')->assertOk();

    $this->actingAs($this->admin)->put('/admin/room-types/'.$type->id, roomTypePayload(['is_active' => '0']));

    $this->get('/en/rooms/garden-suite')->assertNotFound();
    $this->actingAs($this->admin)->get('/admin/room-types')->assertOk()->assertSee('Hidden');
});
