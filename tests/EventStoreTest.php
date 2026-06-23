<?php

declare(strict_types=1);

use PaymosPayments\Service\EventStore;

/**
 * Locks in the race-proof dedup contract of the DBAL-backed EventStore:
 * remember() reserves with a SHORT TTL, commit() extends to the full window,
 * release() drops the in-flight row, and a duplicate event id is rejected.
 */

function test_sw_event_store_remembers_new_event()
{
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    assertTrueValue($store->remember('evt_1', 604800), 'A new event must be remembered.');
    assertTrueValue(isset($connection->rows['evt_1']), 'The dedup row must be inserted.');
    assertTrueValue((int) $connection->rows['evt_1']['expires_at'] > 0, 'The reservation must carry an expiry.');
}

function test_sw_event_store_rejects_duplicate_event()
{
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    assertTrueValue($store->remember('evt_dup', 604800), 'First insert must succeed.');
    assertFalseValue($store->remember('evt_dup', 604800), 'A duplicate event id must return false.');
}

function test_sw_event_store_empty_event_id_is_not_remembered()
{
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    assertFalseValue($store->remember('', 604800), 'An empty event id must not be remembered.');
    assertSameValue(0, count($connection->rows), 'No row is written for an empty event id.');
}

function test_sw_event_store_remember_reserves_short_then_commit_extends()
{
    // The headline durability guarantee: remember() must NOT insert with the
    // full 7-day TTL (a crashed callback would then blacklist the event for a
    // week). It reserves short; commit() extends to the full window only after
    // the order mutation succeeds.
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    $store->remember('evt_commit', 604800);
    $reservedExpiry = (int) $connection->rows['evt_commit']['expires_at'];

    // The reservation must be far shorter than the full dedup window, so a
    // killed process frees the id quickly.
    assertTrueValue($reservedExpiry < time() + 100000, 'remember() must reserve with a SHORT ttl, not the full window.');

    $store->commit();

    assertTrueValue(isset($connection->rows['evt_commit']), 'commit() must keep the durable dedup row.');
    assertTrueValue(
        (int) $connection->rows['evt_commit']['expires_at'] > $reservedExpiry,
        'commit() must extend the reservation to the full dedup window.'
    );
}

function test_sw_event_store_release_drops_in_flight_lock()
{
    // release() must DELETE the row so the server can retry an event whose
    // order mutation threw (or whose process was killed).
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    $store->remember('evt_release', 604800);
    $store->release();

    assertFalseValue(isset($connection->rows['evt_release']), 'release() must delete the in-flight row.');
}

function test_sw_event_store_prunes_expired_reservation_before_insert()
{
    // A stale reservation from a crashed callback (already past its expiry) must
    // be reclaimed so the same id can be retried.
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    $connection->rows['evt_stale'] = array('event_id' => 'evt_stale', 'expires_at' => 1, 'created_at' => 1);

    assertTrueValue($store->remember('evt_fresh', 604800), 'A fresh event must be remembered after pruning expired rows.');
    assertFalseValue(isset($connection->rows['evt_stale']), 'remember() must prune reservations whose expiry has passed.');
}

function test_sw_event_store_commit_without_remember_is_noop()
{
    // A defensive guard: commit()/release() with nothing pending must not throw
    // or touch the table (e.g. a duplicate short-circuits before remember()
    // marks anything pending).
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    $store->commit();
    $store->release();

    assertSameValue(0, count($connection->rows), 'commit()/release() with no pending event must be no-ops.');
}
