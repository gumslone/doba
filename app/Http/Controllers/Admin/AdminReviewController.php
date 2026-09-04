<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Review moderation (§5, FEATURE_REVIEWS).
 *
 * Publish, reply, or delete. What this screen deliberately cannot do is
 * edit a guest's words: a review the hotel can rewrite is worth exactly
 * as much as one the hotel wrote itself. The public answer to a bad
 * review is the response field, in public, under it.
 */
class AdminReviewController extends Controller
{
    public function index(): View
    {
        return view('admin.reviews.index', [
            'pending' => Review::query()->where('is_published', false)->with(['guest', 'booking'])->latest()->get(),
            'published' => Review::query()->published()->with(['guest', 'booking'])->latest('published_at')->paginate(30),
            'aggregate' => Review::aggregate(),
            'enabled' => (bool) config('doba.features.reviews'),
        ]);
    }

    public function publish(Review $review): RedirectResponse
    {
        $review->forceFill([
            'is_published' => true,
            'published_at' => $review->published_at ?? CarbonImmutable::now(),
        ])->save();

        return back()->with('saved', __('admin.review_published'));
    }

    public function unpublish(Review $review): RedirectResponse
    {
        $review->forceFill(['is_published' => false])->save();

        return back()->with('saved', __('admin.review_unpublished'));
    }

    public function respond(Request $request, Review $review): RedirectResponse
    {
        $validated = $request->validate([
            'hotel_response' => ['nullable', 'string', 'max:2000'],
        ]);

        $response = trim((string) ($validated['hotel_response'] ?? ''));

        $review->forceFill([
            'hotel_response' => $response === '' ? null : $response,
            'responded_at' => $response === '' ? null : CarbonImmutable::now(),
        ])->save();

        return back()->with('saved', __('admin.review_responded'));
    }

    public function destroy(Review $review): RedirectResponse
    {
        $review->delete();

        return back()->with('saved', __('admin.review_deleted'));
    }
}
