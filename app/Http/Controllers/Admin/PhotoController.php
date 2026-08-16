<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Concerns\HasMedia;
use App\Models\Gallery;
use App\Models\Media;
use App\Models\RoomType;
use App\Support\Media\MediaUpload;
use App\Support\Routing\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Photo management for everything that can carry photos: each room type
 * and the hotel gallery. Subjects are addressed as "<type>:<id>", and only
 * whitelisted types resolve — the polymorphic media table must never let a
 * URL pick an arbitrary class.
 */
class PhotoController extends Controller
{
    protected const SUBJECT_TYPES = [
        'room-type' => RoomType::class,
        'gallery' => Gallery::class,
    ];

    public function index(): View
    {
        Gallery::hotel(); // ensure the house gallery exists

        return view('admin.photos.index', [
            'roomTypes' => RoomType::query()->ordered()->with(['translation', 'translations', 'media'])->get(),
            'galleries' => Gallery::query()->orderBy('sort_order')->with(['translations', 'media'])->get(),
        ]);
    }

    public function show(string $subject): View
    {
        [$type, $model] = $this->resolve($subject);

        return view('admin.photos.show', [
            'subject' => $subject,
            'model' => $model,
            'title' => $this->titleFor($type, $model),
        ]);
    }

    public function store(Request $request, string $subject, MediaUpload $uploads): RedirectResponse
    {
        [, $model] = $this->resolve($subject);

        $request->validate([
            'photos' => ['required', 'array', 'max:20'],
            // mimes checks the actual file content, not the extension —
            // an uploads directory under the web root takes no chances.
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        foreach ($request->file('photos', []) as $file) {
            $uploads->attach($model, $file);
        }

        return redirect('/admin/photos/'.$subject)->with('saved', __('admin.saved'));
    }

    public function update(Request $request, string $subject, Media $media): RedirectResponse
    {
        [, $model] = $this->resolve($subject);
        $this->assertOwned($model, $media);

        $validated = $request->validate([
            'alt' => ['nullable', 'array'],
            'alt.*' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_cover' => ['sometimes', 'boolean'],
        ]);

        // Alt text keys are locales; anything else in the payload is noise.
        $alt = array_intersect_key(
            array_filter($validated['alt'] ?? [], static fn ($value) => $value !== null && $value !== ''),
            array_flip(Localization::locales())
        );

        $media->update([
            'alt' => $alt === [] ? null : $alt,
            'sort_order' => (int) ($validated['sort_order'] ?? $media->sort_order),
        ]);

        if ((bool) ($validated['is_cover'] ?? false)) {
            $model->media()->where('id', '!=', $media->id)->update(['is_cover' => false]);
            $media->update(['is_cover' => true]);
        }

        return redirect('/admin/photos/'.$subject)->with('saved', __('admin.saved'));
    }

    public function destroy(string $subject, Media $media, MediaUpload $uploads): RedirectResponse
    {
        [, $model] = $this->resolve($subject);
        $this->assertOwned($model, $media);

        $uploads->remove($media);

        return redirect('/admin/photos/'.$subject)->with('saved', __('admin.deleted'));
    }

    /**
     * @return array{0:string,1:Model&HasMedia}
     */
    protected function resolve(string $subject): array
    {
        [$type, $id] = array_pad(explode(':', $subject, 2), 2, '');

        $class = self::SUBJECT_TYPES[$type]
            ?? throw new NotFoundHttpException("Unknown photo subject type [{$type}].");

        /** @var Model&HasMedia $model */
        $model = $class::query()->with(['translations', 'media'])->findOrFail((int) $id);

        return [$type, $model];
    }

    /**
     * A media row reached through one subject's URL must belong to that
     * subject — otherwise any admin URL could edit any photo by id.
     */
    protected function assertOwned(Model&HasMedia $model, Media $media): void
    {
        if ($media->mediable_type !== $model::class || (int) $media->mediable_id !== (int) $model->getKey()) {
            throw new NotFoundHttpException('Photo does not belong to this subject.');
        }
    }

    protected function titleFor(string $type, Model&HasMedia $model): string
    {
        if ($model instanceof RoomType) {
            return (string) ($model->t('name') ?? $model->code);
        }

        if ($model instanceof Gallery) {
            return (string) ($model->t('name') ?? ucfirst($model->code));
        }

        return $type;
    }
}
