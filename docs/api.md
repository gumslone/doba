# Doba Partner API

`/api/v1`. The machine-readable contract is
**[`public/openapi.json`](../public/openapi.json)** — OpenAPI 3.1, served by
every installation at `https://<hotel>/openapi.json`, so you can generate a
client from the hotel you are integrating with rather than from a copy that
may be older than they are.

That file is hand-written and the code is checked against it. Every documented
response is closed — every field required, no extras allowed — so a field
added, renamed or retyped in a controller fails the project's CI before it can
reach you. See `tests/Feature/OpenApiContractTest.php` if you want to see the
teeth.

---

## The three rules

**Money is integer minor units and a currency.** `{"amount": 12500, "currency":
"EUR"}` is €125.00. Never a decimal string, so you cannot parse it as a float,
multiply it by three nights and book a stay for 374.99999999.

**A date is a date.** `check_in` is `2026-09-07`. It is not a timestamp, it has
no timezone, and it does not move when your server is in Kyiv and the hotel is
in Innsbruck. Only genuine instants — `created_at`, `updated_at`,
`hold_expires_at` — are RFC 3339.

**Nothing in the API decides whether a room is free.** Every endpoint calls the
same services the hotel's own website calls. The API and the website cannot
disagree about a night, because there is only one answer and one place it comes
from.

## Authenticating

Two headers, on every request:

```
X-Api-Key-Id: dk_9f3c2a...
X-Api-Secret: <48 characters>
```

The secret is hashed and displayed exactly once, when the hotel issues the key.
There is no recovery path on purpose: a key an admin can read back is a key
that leaks with a database backup. Lose it and the hotel issues a new one.

A rejection is `401` and looks **identical** whether the key id is unknown, the
secret is wrong, or the key was revoked an hour ago. That is deliberate —
telling you which half you got right would let anyone guess the other.

`403` is different and means the key is real: either it lacks the scope for
this route, or it is calling from outside its IP allowlist. If your egress
address changed, you need to know that rather than rotate a key that was fine.

### Scopes

Granted individually. `bookings:write` without `bookings:cancel` is a perfectly
normal grant — a channel manager that takes bookings has no business cancelling
one on the hotel's behalf.

| Scope | Routes |
|---|---|
| `hotel:read` | `GET /hotel`, `GET /room-types` |
| `availability:read` | `GET /availability`, `GET /search` |
| `availability:write` | `PUT /availability` |
| `rates:read` | *(reserved)* |
| `rates:write` | `PUT /rates` |
| `bookings:read` | `GET /bookings`, `GET /bookings/{ref}`, all of `/webhooks` |
| `bookings:write` | `POST /bookings` |
| `bookings:cancel` | `POST /bookings/{ref}/cancel` |

Ask for a **sandbox** key while you build. Bookings it takes are flagged as
tests, so nobody at the front desk prepares a room for your integration suite.

## Errors

RFC 9457, `application/problem+json`:

```json
{
  "type": "https://docs.doba.dev/problems/no-availability",
  "title": "That stay is no longer available.",
  "status": 409,
  "date": "2026-09-08"
}
```

**Branch on `type`.** Those URIs are part of the contract and will not change.
`title` is for a human reading a log and may be reworded at any time.

| `type` | Status | What to do |
|---|---|---|
| `unauthorized` | 401 | Check the key pair. Do not retry with the same one. |
| `forbidden` | 403 | Missing scope, or the call came from outside the allowlist. |
| `not-found` | 404 | No such booking or endpoint. |
| `validation-failed` | 422 | `errors` is keyed by field. Fix and resend. |
| `no-availability` | 409 | The stay went while you were deciding. `date` names the first night that failed — re-search. |
| `idempotency-key-reused` | 409 | You sent the same key with a different body. That is a bug on your side. |
| `not-cancellable` | 409 | Already cancelled, or the guest has checked out. |
| `rate-plan-not-pushable` | 422 | See [Pushing rates](#pushing-availability-and-rates). |

Every response carries `X-Request-Id`, and the hotel can look it up. When
something goes wrong, that id is worth more than a description of it.

## Taking a booking

`POST /bookings` **requires an `Idempotency-Key` header.** This is not
optional, because a partner whose request times out will retry, and an endpoint
that cannot tell a retry from a second booking sells the room twice.

- Same key, same body → the original response, **byte for byte**, plus
  `Idempotent-Replay: true`. Safe to retry as often as you like.
- Same key, different body → `409 idempotency-key-reused`. Replaying the old
  response there would hide a real bug in your client.

A new booking is `pending` and holds its inventory until `hold_expires_at`.
That is a hold, not a booking: if it is not confirmed or paid by then, the room
goes back on sale. `hold_expires_at` is `null` once the booking is confirmed.

## Reading bookings

`GET /bookings` is **cursor-paginated**. Follow `next_cursor` until it is
`null`.

Cursor rather than offset on purpose: offset pagination over a table that is
being written to while you page through it skips rows and repeats others, and
you would lose bookings without ever seeing an error.

Poll with `updated_since` and you will see everything that changed, including
cancellations.

## Pushing availability and rates

`PUT`, and meant literally: these are **idempotent range writes**, not
increments. Send the identical body twice and nothing changes that the first
call did not — which is exactly what a push that timed out needs.

`weekdays` is a bitmask (Mon 1, Tue 2, Wed 4, Thu 8, Fri 16, Sat 32, Sun 64),
so "Saturdays in July, minimum stay three" is one entry rather than five.
Weekends are `96`; the default, every day, is `127`.

Nights where the allotment you pushed is below what is **already sold and
held** are refused individually and listed in `refused`. The rest of the push
still applies — one oversold night should not throw a six-month range away, and
you need to know which night rather than which request.

```json
{ "nights_updated": 178, "refused": [{ "room_type": "DBL", "date": "2026-12-31" }] }
```

**Prices cannot be pushed per rate plan.** In Doba a rate plan is an
*adjustment off the room's price*, not an independent price, so a per-plan push
would be stored and never read. Sending `rate_plan` is refused with
`rate-plan-not-pushable` rather than accepted and quietly ignored — you would
otherwise spend a week wondering why your push had no effect.

## Webhooks

Subscribe with `POST /webhooks`. The signing secret is returned **once**.

Every delivery carries:

```
X-Signature: t=1789012345,v1=<hex>
X-Event-Id: 5b2f...
X-Event-Type: booking.created
```

where the hex is `HMAC-SHA256(secret, "<t>.<raw request body>")`. **Sign the raw
body, not a re-serialisation of it** — key order will not survive a round trip
through your JSON library. The timestamp is inside the signed string, so a
delivery captured and replayed hours later fails verification even though the
body is byte-identical; reject anything whose `t` is older than your tolerance.

Two properties you must design for:

- **At least once.** A delivery that timed out after you processed it will
  arrive again. Dedupe on `event_id`.
- **Possibly out of order.** `booking.cancelled` can arrive before
  `booking.updated` for the same booking, because they retry on independent
  schedules. Every payload carries the resource's `updated_at` — ignore an
  event older than what you have stored, or you will eventually resurrect a
  cancelled booking.

Retries back off 1m, 5m, 30m, 2h, 6h, 24h. An endpoint that fails 20 times
running is disabled, and the hotel is told. `POST /webhooks/{id}/test` sends a
real, really-signed delivery so you can prove your verification works before a
booking depends on it.

## Rate limits and stability

Requests are throttled per key. Ranges on `GET /availability` are capped at 370
days, and ARI pushes at 200 entries per call — a bounded answer beats a timeout.

`/api/v1` will not break inside its version. New **optional** fields may be
added; if you parse strictly, ignore what you do not recognise rather than
failing on it.
