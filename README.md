# Hermetica PHP client

Generated from `docs/api/openapi.json` by `node scripts/gen-sdks.mjs` (2038 operations). PHP 8.1+, curl and json extensions, no other dependencies.

```php
$hm = new Hermetica\Client('hm_sk_...', 'https://api.example.com');
$page = $hm->getV1Profiles(['query' => ['limit' => 25]]);
$hm->postV1Events(['body' => ['name' => 'signed_up', 'profile' => ['email' => 'ada@example.com']], 'idempotencyKey' => 'signup-ada-1']);
foreach ($hm->paginate('getV1Profiles', ['query' => ['limit' => 100]]) as $profile) { echo $profile['id']; }
```

- One method per operationId (camelCase). Argument: `['path' => [...], 'query' => [...], 'body' => ..., 'idempotencyKey' => '...']`.
- Every non-GET call sends an `Idempotency-Key` (random unless you pass one); reuse yours on retries.
- Errors throw `Hermetica\HermeticaException` (`status`, `errorCode`, `details`).
- Consent and suppression are enforced by the API; the client cannot bypass them.
- Regenerate after the API changes: `node scripts/gen-sdks.mjs`.
