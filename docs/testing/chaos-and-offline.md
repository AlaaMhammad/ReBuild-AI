# Chaos & Offline Scenarios

How the sync pipeline behaves when the network, the device clock, the queue or storage misbehave, and how each behaviour is verified.

**Verified by** column:
- **Automated**: covered by the PHPUnit suite (file and test named); runs against PostGIS in CI.
- **Manual**: requires killing real processes; follow the procedure in §6.
- **Design**: guaranteed by a database constraint or by construction; no dedicated test.

Run the automated suite:

```bash
docker compose run --rm app php vendor/bin/phpunit --testsuite Feature --filter Sync
```

---

## 1. Network failures

| # | Scenario | Expected behaviour | Verified by |
|---|---|---|---|
| N1 | Connection drops **before** the request reaches the server | Nothing recorded. Device retries with the same `idempotency_key` and the identical body; the first successful attempt returns `201` | Automated: `BatchSyncTest::retrying_the_same_batch_replays_the_original_result_without_duplicates` |
| N2 | Connection drops **after** the server committed, before the device got the response | Retry returns `200` with `Idempotent-Replayed: true` and the original `results`, `id_map` and `acknowledged_client_uuids`. No duplicate rows and no second background job | Automated: same test, plus `SyncBackgroundProcessingTest::replays_never_queue_a_second_job` |
| N3 | Device fires a retry while the first attempt is still being ingested (slow uplink, aggressive timeout) | Second request gets `409 sync_in_progress` with `Retry-After: 5`. Retrying after that returns the replay | Automated: `BatchSyncTest::a_retry_racing_an_in_flight_attempt_gets_409_with_retry_after` |
| N4 | Connection drops in the middle of an image upload (`PUT …/content`) | Truncated body fails the size/hash check → `422`, nothing stored, image stays `pending_upload`. Re-upload the full file | Automated: `ImageContentUploadTest::content_that_does_not_match_the_declared_hash_is_refused` |
| N5 | Upload succeeded but the response was lost | Re-uploading returns `200` without rewriting the file | Automated: `ImageContentUploadTest::re_uploading_stored_content_is_an_idempotent_no_op` |
| N6 | Request body corrupted or truncated in transit (JSON) | `422` validation error, or a different payload hash. Nothing committed | Automated: `BatchSyncTest::malformed_batches_are_rejected_with_field_errors` |
| N7 | Very slow link, large batch | Batches are capped (`SYNC_MAX_ITEMS_PER_BATCH`, 10 inline images, 2 MiB per inline image). Larger work must be split, so a drop costs at most one small batch | Automated: `BatchSyncTest::oversized_batches_must_be_split` |

### Client retry policy (normative)

```
on network error, timeout, 409, 429, 500, 502, 503, 504:
    wait = min(base * 2^attempt, 15 min) * random(0.5, 1.5)   # base = 5 s; honour Retry-After when present
    resend the SAME idempotency_key with the BYTE-IDENTICAL body
on 2xx:
    mark acknowledged_client_uuids as synced
    keep failed items with retryable = true in the queue (they go into a NEW batch with a NEW key)
    drop failed items with retryable = false from the queue and surface them to the user
on 401:
    refresh or re-authenticate (same device_id), then resend the same batch
on 403 / 422:
    do not retry unchanged; surface the error
```

A batch body must be serialised **once** and persisted on the device. The server hashes the raw body, so re-serialising (different key order or float formatting) on retry looks like key reuse and returns `422 idempotency_key_reused`.

---

## 2. Duplicate and conflicting submissions

| # | Scenario | Expected behaviour | Verified by |
|---|---|---|---|
| D1 | Same record in two different batches (device re-batched after a crash) | Second occurrence: `skipped` / `unchanged` (same revision). Acknowledged, not duplicated | Automated: `BatchSyncTest::revisions_decide_between_update_unchanged_and_stale` |
| D2 | Same idempotency key reused for **different** content (client bug) | `422 idempotency_key_reused` with the original `batch_id`. Stored data unchanged | Automated: `BatchSyncTest::reusing_an_idempotency_key_for_different_content_is_rejected` |
| D3 | Two devices of the same organization push the same `client_uuid` at the same instant (different keys) | Row lock plus `UNIQUE (organization_id, client_uuid)`: one wins. The loser reports `concurrent_write` (retryable); on resend it resolves to `updated`, `unchanged` or `stale_revision` | Design: unique constraint + per-item savepoint (`SyncBatchIngestor::attempt`) |
| D4 | Lock store (Redis) flushed while a batch is being ingested; a retry arrives | `UNIQUE (organization_id, idempotency_key)` lets only one transaction record the batch. The loser catches the violation and returns the winner's result as a replay | Design: `SyncBatchService::submit` fallback path |
| D5 | Same `client_uuid` already used by **another organization** | No collision: uniqueness is per organization. The other tenant's record is untouched | Automated: `BatchSyncTest::the_same_client_uuid_in_another_organization_never_collides` |
| D6 | Older revision arrives after a newer one (out-of-order delivery) | `skipped` / `stale_revision`. The newer server state is kept | Automated: `BatchSyncTest::revisions_decide_between_update_unchanged_and_stale` |
| D7 | Device edits an assessment a coordinator is already reviewing | `skipped` / `locked_by_review`. Server state wins | Automated: `BatchSyncTest::records_under_review_are_not_overwritten_by_devices` |
| D8 | Household registered twice in the same organization (same national ID, different `client_uuid`) | `failed` / `national_id_already_registered` (not retryable). `context` names the existing household so the device can link to it | Automated: `BatchSyncTest::a_national_id_already_registered_in_the_organization_is_reported_with_the_existing_record` |
| D9 | Image re-declared with a different hash or assessment | `failed` / `image_immutable` | Automated: `BatchSyncTest::a_re_declared_image_with_a_different_hash_is_refused` |

---

## 3. Partial batches and dependencies

| # | Scenario | Expected behaviour | Verified by |
|---|---|---|---|
| P1 | One item in a batch is invalid at the data level | Only that item fails (savepoint rollback). The others commit; batch status `partially_failed` | Automated: `BatchSyncTest::an_unresolvable_reference_fails_only_that_item` |
| P2 | Assessment references a household that failed in the same batch | Assessment fails `beneficiary_not_found` (retryable). Images of that assessment fail `assessment_not_found` (retryable) | Design: `BatchOutcome::hasFailed` check in `SyncBatchIngestor` |
| P3 | Assessment references a household synced in an **earlier** batch | Resolved from the database | Automated: `BatchSyncTest::references_resolve_to_records_synced_in_earlier_batches` |
| P4 | Inline image fails its hash check | Image fails `image_hash_mismatch`; no file is left on disk; the assessment still commits | Automated: `BatchSyncTest::inline_content_that_does_not_match_its_hash_is_rejected_and_not_stored` |
| P5 | Database error mid-batch (connection lost) | Whole transaction rolls back, including the `sync_batches` row. Files written for it are deleted. The device retries the same key | Design: `SyncBatchService::ingestNew` catch block |

---

## 4. Clock drift

| # | Scenario | Expected behaviour | Verified by |
|---|---|---|---|
| C1 | Device clock off by under 5 minutes | Timestamps stored as sent, converted to UTC. `clock.corrected = false` | Automated: `BatchSyncTest::timestamps_with_offsets_are_stored_in_utc` |
| C2 | Device clock **behind** by hours (battery died, clock reset) | All device timestamps shifted by the measured skew. `clock.corrected = true`; the skew is recorded on `sync_batches.clock_skew_seconds` | Automated: `BatchSyncTest::large_clock_skew_is_corrected_and_future_timestamps_are_clamped`, `DeviceClockTest` |
| C3 | Device clock **ahead** (wrong year or time zone) | Same correction with a negative skew | Automated: `DeviceClockTest::large_negative_skew_shifts_timestamps_back` |
| C4 | A timestamp still in the future after correction (clock changed between capture and send) | Clamped to the server receive time; warning `<field>_clamped` in `results.<section>.warnings[]` | Automated: `BatchSyncTest::large_clock_skew_is_corrected_and_future_timestamps_are_clamped` |
| C5 | Timestamps sent with a non-UTC offset (`+03:00`) | Stored as the correct UTC instant | Automated: `BatchSyncTest::timestamps_with_offsets_are_stored_in_utc` |

Device time is never used for ordering, conflict resolution or security. Conflicts are resolved by `revision`, not by timestamps.

---

## 5. Background processing and storage

| # | Scenario | Expected behaviour | Verified by |
|---|---|---|---|
| B1 | Horizon worker crashes or Redis loses the queue after a batch was acknowledged | Batch stays `processing`. `rebuild:sync:recover-stale` (every 10 min) re-dispatches it after 15 min. Jobs are idempotent and unique | Automated: `SyncBackgroundProcessingTest::stale_batches_and_images_are_re_dispatched_by_the_recovery_command`; Manual: M2 |
| B2 | Job re-runs on an already-finished batch | No-op | Automated: `SyncBackgroundProcessingTest::the_job_is_idempotent_on_terminal_batches` |
| B3 | Job exhausts its retries (for example, storage down for an hour) | Batch marked `partially_failed` (or `failed`) with `error_message`; the item outcome and the acknowledgement are kept | Automated: `SyncBackgroundProcessingTest::a_permanently_failing_job_records_the_error_without_losing_the_outcome` |
| B4 | Stored image file corrupted or tampered with at rest | Processor detects the hash mismatch, removes the file, marks the image `failed`. It reappears in `images_awaiting_upload` and the device re-uploads it | Automated: `ImageContentUploadTest::a_processed_image_whose_stored_file_was_corrupted_is_reset_for_re_upload` |
| B5 | Object storage unavailable during an upload | Batch item: `storage_unavailable` (retryable). Content endpoint: `503`. Nothing is marked uploaded | Design: `ImageBinaryStore::store` |
| B6 | Non-image bytes with a matching hash | `422 unsupported_media_type`; content type is sniffed, not trusted from headers | Automated: `ImageContentUploadTest::non_image_content_is_refused_even_with_a_matching_hash` |

---

## 6. Authentication while offline

| # | Scenario | Expected behaviour | Verified by |
|---|---|---|---|
| A1 | Token expires while the device is offline | First sync returns `401`. Agent signs in again with the same `device_id`; the queued batches are sent unchanged with the new token | Automated (server side): `TokenLifecycleTest::expired_tokens_are_rejected` |
| A2 | Device lost; the admin revokes its tokens; the device later reconnects | `401` on every request. Queued data remains only on the device | Automated: `AuthorizationTest::an_admin_can_revoke_every_token_of_a_user_in_their_organization` |
| A3 | A different phone tries to push with a stolen token but its own `device.id` | `403`. A field token pushes only for its own device | Automated: `BatchSyncTest::a_field_token_may_only_push_for_its_own_device` |
| A4 | Organization deactivated while devices are offline | `401` on reconnect; nothing is ingested | Automated: `TokenLifecycleTest::deactivating_the_organization_cuts_off_live_tokens` |

---

## 7. Manual chaos procedures

Run against the Docker Compose stack (`docker compose --profile workers up -d api horizon scheduler`).

### M1: Response lost after commit

1. Push a batch through Postman (**Sync / Push batch**).
2. Run **Sync / Replay last batch**.
3. Expect `200`, header `Idempotent-Replayed: true`, and the same `batch_id`.
4. Check `SELECT count(*) FROM sync_batches WHERE idempotency_key = '<key>'` returns `1`.

### M2: Worker crash mid-processing

```bash
docker compose stop horizon
```

Push a batch with an inline image (**Sync / Push batch with inline image**). The batch remains `processing` and the image remains `uploaded`.

```bash
docker compose start horizon
```

Expect the batch to become `completed` within seconds: the job was still in Redis. To simulate **queue loss** instead, run `docker compose exec redis redis-cli FLUSHALL` while Horizon is stopped, start Horizon again, wait 15 minutes (or run the next command), and expect `completed`.

```bash
docker compose run --rm app php artisan rebuild:sync:recover-stale --minutes=0
```

### M3: Redis down during sync

```bash
docker compose stop redis
```

Push a batch. Expect `500`: the idempotency lock cannot be taken and nothing is committed.

```bash
docker compose start redis
```

Retry the same batch and expect `201`.

*Last executed 2026-10-12 against the Compose stack: `[down] HTTP 500, 0 sync_batches rows` → `[up] HTTP 201, 1 row`.*

### M4: Database restart mid-batch

Push a large batch (500 assessments) and run `docker compose restart postgres` while it is in flight. Expect `500` and no `sync_batches` row for the key. The retry ingests the batch exactly once.

### M5: Clock drift on a real device

Set the Android clock two hours back, capture an assessment, restore automatic time, sync. Expect `clock.corrected: true` and an `assessed_at` that matches the real capture time to within the network latency.

---

## 8. Known limitations

- **Skew correction assumes a constant offset.** If the device clock was changed between capture and sync, corrected timestamps can still be off. Clamping only guarantees they are never in the future.
- **Large binaries are not resumable.** Upload uses a single `PUT`. Clients should resize photos (≤ 2 MP JPEG, about 300–600 KB) before upload. Chunked or resumable upload is a candidate enhancement.
- **Replay `status` is live.** A replay returns the original item results but the batch's current `status` and `images_awaiting_upload`, which may have progressed since the first response.
