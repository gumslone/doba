<?php

declare(strict_types=1);

use App\Models\Gallery;
use App\Models\Media;
use App\Models\RoomType;
use App\Models\User;
use App\Support\Media\ResponsiveImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function photoFile(string $name = 'photo.jpg', int $width = 1200, int $height = 800): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('doba.locales', ['en', 'de']);
    config()->set('doba.seo.image_widths', [480, 768]);

    $this->roomType = RoomType::create([
        'code' => 'DBL',
        'base_occupancy' => 2,
        'max_occupancy' => 2,
        'default_rate' => 12500,
        'total_units' => 2,
    ]);

    $this->roomType->translations()->create([
        'locale' => 'en', 'slug' => 'double-room', 'name' => 'Double room',
    ]);

    $this->admin = User::factory()->create();
    $this->subject = 'room-type:'.$this->roomType->id;
});

it('locks photo management behind admin login', function (): void {
    $this->get('/admin/photos')->assertRedirect('/admin/login');
    $this->post('/admin/photos/'.$this->subject)->assertRedirect('/admin/login');
});

it('uploads a photo, stores dimensions and generates derivatives', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/photos/'.$this->subject, ['photos' => [photoFile()]])
        ->assertRedirect('/admin/photos/'.$this->subject);

    $media = Media::sole();

    expect($media->width)->toBe(1200)
        ->and($media->height)->toBe(800)
        // First photo of a subject becomes its cover automatically.
        ->and($media->is_cover)->toBeTrue();

    Storage::disk('public')->assertExists($media->path);
    Storage::disk('public')->assertExists(
        ResponsiveImage::derivativePath($media->path, 480)
    );

    // The original filename never becomes the stored path.
    expect($media->path)->not->toContain('photo.jpg');
});

it('renders the uploaded photo on the public room page with a srcset', function (): void {
    $this->actingAs($this->admin)->post('/admin/photos/'.$this->subject, ['photos' => [photoFile()]]);

    $html = $this->get('/en/rooms/double-room')->assertOk()->getContent();

    expect($html)->toContain('480w')
        ->toContain('768w')
        ->toContain('width="1200"')
        ->toContain('fetchpriority="high"'); // the LCP image
});

it('rejects a non-image upload', function (): void {
    $this->actingAs($this->admin)
        ->post('/admin/photos/'.$this->subject, [
            'photos' => [UploadedFile::fake()->create('payload.php', 8, 'application/x-php')],
        ])
        ->assertSessionHasErrors('photos.0');

    expect(Media::count())->toBe(0);
});

it('saves per-locale alt text and renders it in that language', function (): void {
    $this->actingAs($this->admin)->post('/admin/photos/'.$this->subject, ['photos' => [photoFile()]]);
    $media = Media::sole();

    $this->actingAs($this->admin)->put('/admin/photos/'.$this->subject.'/'.$media->id, [
        'alt' => ['en' => 'A double room', 'de' => 'Ein Doppelzimmer', 'xx' => 'ignored'],
        'sort_order' => 3,
    ])->assertRedirect();

    expect($media->fresh()->alt)->toBe(['en' => 'A double room', 'de' => 'Ein Doppelzimmer'])
        ->and($media->fresh()->sort_order)->toBe(3);

    $this->get('/en/rooms/double-room')->assertOk()->assertSee('alt="A double room"', false);
});

it('moves the cover to another photo', function (): void {
    $this->actingAs($this->admin)->post('/admin/photos/'.$this->subject, [
        'photos' => [photoFile('a.jpg'), photoFile('b.jpg')],
    ]);

    [$first, $second] = Media::query()->orderBy('id')->get()->all();

    expect($first->is_cover)->toBeTrue()->and($second->is_cover)->toBeFalse();

    $this->actingAs($this->admin)
        ->put('/admin/photos/'.$this->subject.'/'.$second->id, ['is_cover' => '1'])
        ->assertRedirect();

    // Exactly one cover, always.
    expect($first->fresh()->is_cover)->toBeFalse()
        ->and($second->fresh()->is_cover)->toBeTrue();
});

it('deletes the row, the original and every derivative', function (): void {
    $this->actingAs($this->admin)->post('/admin/photos/'.$this->subject, ['photos' => [photoFile()]]);
    $media = Media::sole();
    $derivative = ResponsiveImage::derivativePath($media->path, 480);

    $this->actingAs($this->admin)
        ->delete('/admin/photos/'.$this->subject.'/'.$media->id)
        ->assertRedirect();

    expect(Media::count())->toBe(0);
    Storage::disk('public')->assertMissing($media->path);
    Storage::disk('public')->assertMissing($derivative);
});

it('promotes the next photo when the cover is deleted', function (): void {
    $this->actingAs($this->admin)->post('/admin/photos/'.$this->subject, [
        'photos' => [photoFile('a.jpg'), photoFile('b.jpg')],
    ]);

    [$first, $second] = Media::query()->orderBy('id')->get()->all();

    $this->actingAs($this->admin)->delete('/admin/photos/'.$this->subject.'/'.$first->id);

    // A subject with photos must never be left coverless.
    expect($second->fresh()->is_cover)->toBeTrue();
});

it('refuses a photo reached through the wrong subject', function (): void {
    $gallery = Gallery::hotel();

    $this->actingAs($this->admin)->post('/admin/photos/'.$this->subject, ['photos' => [photoFile()]]);
    $media = Media::sole();

    // The photo belongs to the room type, not the gallery.
    $this->actingAs($this->admin)
        ->put('/admin/photos/gallery:'.$gallery->id.'/'.$media->id, ['sort_order' => 9])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete('/admin/photos/gallery:'.$gallery->id.'/'.$media->id)
        ->assertNotFound();

    expect(Media::count())->toBe(1);
});

it('refuses an unknown subject type', function (): void {
    // The polymorphic media table must never let a URL pick a class.
    $this->actingAs($this->admin)->get('/admin/photos/user:1')->assertNotFound();
    $this->actingAs($this->admin)->get('/admin/photos/App%5CModels%5CUser:1')->assertNotFound();
});

it('shows hotel gallery photos on the home page and in the Hotel schema', function (): void {
    $gallery = Gallery::hotel();

    $this->actingAs($this->admin)->post('/admin/photos/gallery:'.$gallery->id, [
        'photos' => [photoFile('hero.jpg'), photoFile('second.jpg')],
    ]);

    $html = $this->get('/en')->assertOk()->getContent();

    $hotel = collect(jsonLdBlocks($html))->firstWhere('@type', 'Hotel');

    expect($hotel['image'])->toHaveCount(2)
        // The cover is the hero, so it is eager and high priority.
        ->and($html)->toContain('fetchpriority="high"');
});
