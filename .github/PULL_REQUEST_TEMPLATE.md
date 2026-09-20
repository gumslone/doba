## What and why

<!-- What changed, and the situation that made it necessary. If it fixes a bug, how did the bug show itself? -->

## Checked

- [ ] `vendor/bin/pint` and `vendor/bin/phpstan analyse --memory-limit=1G` are clean
- [ ] `php artisan test` passes (MySQL too, if the change touches queries, dates or locking)
- [ ] New behaviour has a test
- [ ] Guest-facing strings are in all six languages
- [ ] API response changes are in `resources/api/openapi.yaml` and `php artisan doba:openapi` was run
