---
title: Idempotency
description: Create each Pey charge at most once, even with double clicks, queue retries and timeouts.
---

# Idempotency

Pey has **no idempotency keys**. If your application sends the same charge twice (a double click, a queue job that retries, a deploy that restarts a worker, a retry after a timeout), Pey creates two charges. The SDK never retries a POST itself, but it cannot stop your application from calling twice. `IdempotentRequests` does.

## Usage

```php
use Dev1\Pey\Idempotency\IdempotentRequests;

$idempotent = new IdempotentRequests($pey, $store);   // $store: a shared IdempotencyStore (see below)

$qr   = $idempotent->createQr("order-{$order->id}:qr", $qrRequest);
$push = $idempotent->createPushNotification("order-{$order->id}:pn", $pushRequest);
$link = $idempotent->createPaymentLink($linkRequest);   // keyed by its clientReferenceId, which is required
```

For each key, Pey is called **at most once**. Calling again with the same key and the same parameters **returns the stored result without calling Pey**. Derive keys deterministically from your own ids, so every retry of the same order uses the same key.

## What happens on each call

| The key… | Result |
|---|---|
| has never been used | the request is sent once and its result stored |
| completed with the same parameters | the stored result is returned; **nothing is sent** |
| was used with **different parameters** | `IdempotencyKeyReusedException`: one order's charge is never returned for another |
| belongs to an attempt still running | `IdempotencyInProgressException`: try again shortly |
| belongs to an attempt with an **unknown outcome** | `IdempotencyOutcomeUnknownException`: reconcile first (below) |

After a failure, the key is released (so the same call may be sent again) **only when Pey certainly did not act**:
- local validation;
- any 4xx answer from Pey;
- a request that was never sent.

Everything else keeps the key blocked as "unknown": timeouts, network errors after sending, any 5xx, an unreadable response, an unexpected error, or a process that died mid-request. In all of those cases the charge **may exist**.

## Reconciling an unknown outcome

The SDK never resends a blocked key on its own. Find out what happened, then decide:

- **QR codes and payment links.** If your application never received the QR string or the link URL, nobody can pay that charge: the payer needs the QR or the URL. Releasing the key and creating a new charge is low-risk.
- **Push notifications.** These are high-risk: Pey sends the request straight to the payer's phone, so the payer may have received it even though your application did not get a response. Before releasing:
  1. wait until the push notification's expiration has passed (360 seconds by default);
  2. look for an `API_CODI_PN` transaction with the same amount around that time, with `$pey->transactions()->listForApiKey()` or `allForApiKey()`;
  3. only if there is none, release the key and try again.

When you are sure nothing was created:

```php
use Dev1\Pey\Idempotency\Operation;

$idempotent->release(Operation::CodiPushNotification, "order-{$order->id}:pn");   // logged at "notice"
```

Releasing a key whose charge exists allows a duplicate charge.

## The store

`IdempotencyStore` has four operations, which any shared cache can provide:

| Method | Redis | Laravel cache |
|---|---|---|
| `acquire($key, $record, $ttl)`: store only if absent, **atomically** | `SET key value NX EX ttl` | `Cache::add()` |
| `remember($key, $record, $ttl)` | `SET key value EX ttl` | `Cache::put()` |
| `release($key)` | `DEL key` | `Cache::forget()` |
| `recall($key)` | `GET key` | `Cache::get()` |

Requirements:
- `acquire()` must be atomic across every process that creates charges. Otherwise two workers can both send.
- Records must not be evicted early. Use a store without eviction under memory pressure, such as a dedicated Redis database with `noeviction` or a database table. An evicted record allows a duplicate charge.
- On storage errors, throw. A failing `acquire()` means nothing is sent.

`InMemoryIdempotencyStore` is for tests and single-process scripts only. The Laravel and Symfony integrations provide production stores.

## Settings

```php
new IdempotentRequests($pey, $store, retentionSeconds: 604_800, staleAfterSeconds: 300);
```

- `retentionSeconds` (default **7 days**): how long results are replayed and unknown outcomes stay blocked. Keep it longer than any retry horizon of your queues and users. After it expires the key is free again.
- `staleAfterSeconds` (default **300**): an attempt that has not finished after this long is treated as crashed, and its outcome as unknown. Keep it longer than your HTTP timeout.

Keys are stored and logged only as SHA-256 hashes.

## Limits

- The guarantee is only as strong as the store.
- It protects against repeating a **key**. Two different keys for the same order are two charges, so derive keys from your order ids.
- Only calls made through `IdempotentRequests` are protected.
