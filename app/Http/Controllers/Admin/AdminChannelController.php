<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Channels\ChannelSyncService;
use App\Http\Controllers\Controller;
use App\Models\ChannelBooking;
use App\Models\ChannelFeed;
use App\Models\RoomType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class AdminChannelController extends Controller
{
    /** The channels a tier-1 iCal sync actually talks to. */
    public const CHANNELS = ['booking_com', 'airbnb', 'vrbo', 'expedia', 'other'];

    public function index(): View
    {
        return view('admin.channels.index', [
            'feeds' => ChannelFeed::query()->with('roomType.translations')->orderBy('name')->get(),
            'roomTypes' => RoomType::query()->with('translations')->orderBy('sort_order')->get(),
            // The queue that needs a human: stays an OTA stopped publishing
            // too close to arrival for the guard to act on its own.
            'review' => ChannelBooking::query()
                ->holding()
                ->where('needs_review', true)
                ->with(['feed', 'roomType.translations'])
                ->orderBy('check_in')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.channels.edit', [
            'feed' => new ChannelFeed(['channel' => 'booking_com']),
            'roomTypes' => $this->roomTypes(),
        ]);
    }

    public function edit(ChannelFeed $feed): View
    {
        return view('admin.channels.edit', [
            'feed' => $feed,
            'roomTypes' => $this->roomTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request, new ChannelFeed);
    }

    public function update(Request $request, ChannelFeed $feed): RedirectResponse
    {
        return $this->save($request, $feed);
    }

    public function destroy(ChannelFeed $feed, ChannelSyncService $channels): RedirectResponse
    {
        // Give the rooms back on the way out, otherwise deleting a feed
        // leaves its nights blocked with nothing left to explain why.
        $channels->releaseFeed($feed);

        $feed->delete();

        return redirect('/admin/channels')->with('status', __('admin.channel_deleted'));
    }

    /**
     * Run one feed now, so a hotelier who just fixed a URL gets an answer
     * instead of waiting a quarter of an hour to find out.
     */
    public function sync(ChannelFeed $feed, ChannelSyncService $channels): RedirectResponse
    {
        $result = $channels->sync($feed);

        return redirect('/admin/channels')->with(
            'status',
            isset($result['failed'])
                ? __('admin.channel_sync_failed', ['error' => (string) $result['error']])
                : __('admin.channel_synced', [
                    'events' => (int) ($result['events'] ?? 0),
                    'added' => (int) ($result['added'] ?? 0),
                    'released' => (int) ($result['released'] ?? 0),
                ]),
        );
    }

    /**
     * Resolve a flagged stay: either it really was cancelled and the room
     * goes back on sale, or the feed was wrong and the block stands.
     */
    public function resolve(Request $request, ChannelBooking $channelBooking, ChannelSyncService $channels): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['release', 'keep'])],
        ]);

        if ($validated['decision'] === 'release') {
            $channels->releaseBlock($channelBooking);
        } else {
            // Keep the room blocked and stop asking: the counter resets so
            // a feed that keeps omitting it does not re-flag it every hour.
            $channelBooking->forceFill([
                'needs_review' => false,
                'missing_syncs' => 0,
                'missing_since' => null,
            ])->save();
        }

        return redirect('/admin/channels')->with('status', __('admin.channel_review_resolved'));
    }

    protected function save(Request $request, ChannelFeed $feed): RedirectResponse
    {
        $validated = $request->validate([
            'room_type_id' => ['required', 'exists:room_types,id'],
            'channel' => ['required', Rule::in(self::CHANNELS)],
            'name' => ['required', 'string', 'max:255'],
            // http(s) only: an OTA feed is a URL, and accepting file:// or
            // gopher:// here would turn this form into an SSRF primitive.
            'import_url' => ['nullable', 'url:http,https', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $feed->fill($validated + ['is_active' => $request->boolean('is_active')])->save();

        return redirect('/admin/channels')->with('status', __('admin.saved'));
    }

    /**
     * @return Collection<int,array{id:int,label:string}>
     */
    protected function roomTypes(): Collection
    {
        return RoomType::query()
            ->with('translations')
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (RoomType $type): array => [
                'id' => $type->id,
                'label' => $type->t('name') ?? $type->code,
            ]);
    }
}
