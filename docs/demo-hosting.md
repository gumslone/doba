# Hosting a public demo

A public demo is one switch: `DOBA_DEMO=true`. With it on, a Doba install

- builds the demo hotel by itself on first boot — no wizard — with guests
  arriving today, people in the house, doors waiting for housekeeping,
  reviews and unread enquiries, so the front desk is alive when somebody
  opens it;
- says it is a demo on every page and prints the admin login there, because
  standing behind the desk is what convinces a hotelier;
- keeps the desk, bookings, rates, room types, rooms and housekeeping fully
  working, and makes read-only everything a stranger could use against the
  next visitor or the host: website content, photos, styles, settings, mail,
  API keys, channels, the directory, the updater and the account;
- never sends mail (the mailer is forced to the log) and never takes a
  payment (online payment is forced off);
- sends `noindex`, so the demo never competes with your own site in search;
- **drops its database and rebuilds every night** at `DOBA_DEMO_RESET_AT`
  (default `04:00`).

`php artisan doba:demo:reset` is the command behind that last point. It
refuses to run unless `DOBA_DEMO=true`, and it has no `--force`.

## With Docker (any VPS, about €4 a month)

```bash
docker run -d --name doba-demo --restart unless-stopped \
  -p 8080:80 -v doba-demo:/data \
  -e DOBA_DEMO=true \
  -e DOBA_URL=https://demo.example.com \
  -e DOBA_ADMIN_EMAIL=demo@example.com \
  -e DOBA_ADMIN_PASSWORD=demo-demo-demo \
  ghcr.io/gumslone/doba
```

Put Caddy in front for the certificate — two lines:

```
demo.example.com {
    reverse_proxy localhost:8080
}
```

## Without Docker

Install normally, add `DOBA_DEMO=true` to `.env`, then run
`php artisan doba:demo:reset` once. The nightly reset needs the scheduler,
which runs from cron or, without one, from visitor traffic.

## What it costs to run

The demo hotel is SQLite and a handful of generated images: 512 MB of RAM
is plenty. Nothing in it is personal data — every guest is invented and
every address is `@example.com`.
