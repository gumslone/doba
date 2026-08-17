<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminApiClientController extends Controller
{
    public function index(): View
    {
        return view('admin.api.index', [
            'clients' => ApiClient::query()->orderByDesc('id')->get(),
            'scopes' => ApiClient::SCOPES,
            // Shown once, straight after creation, and never again.
            'freshSecret' => session('api_secret'),
            'freshKeyId' => session('api_key_id'),
            'recent' => DB::table('api_request_logs')
                ->orderByDesc('id')
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => [Rule::in(ApiClient::SCOPES)],
            'sandbox' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'ip_allowlist' => ['nullable', 'string', 'max:1000'],
        ]);

        ['client' => $client, 'secret' => $secret] = ApiClient::issue(
            $validated['name'],
            $validated['scopes'],
            $request->boolean('sandbox'),
        );

        $client->forceFill([
            'expires_at' => isset($validated['expires_at'])
                ? CarbonImmutable::parse($validated['expires_at'])->endOfDay()
                : null,
            'ip_allowlist' => $this->addresses($validated['ip_allowlist'] ?? null),
        ])->save();

        // Flashed rather than shown on a page that can be reloaded or
        // shared: the secret exists in one response and nowhere else.
        return redirect('/admin/api')->with([
            'api_secret' => $secret,
            'api_key_id' => $client->key_id,
        ]);
    }

    public function destroy(ApiClient $api): RedirectResponse
    {
        // Revoked, not deleted: the request log points at it, and a
        // partner asking "why did my key stop working" deserves an answer
        // better than "there is no such key".
        $api->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        return redirect('/admin/api')->with('saved', __('admin.api_revoked'));
    }

    /**
     * @return array<int,string>|null
     */
    protected function addresses(?string $input): ?array
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        $addresses = array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', $input) ?: []),
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false,
        ));

        return $addresses === [] ? null : $addresses;
    }
}
