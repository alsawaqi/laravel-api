<?php

/*
 * QUARANTINED.
 *
 * These tests relied on RefreshDatabase + model factories, but this app has no
 * migrations (the SQL-first `*_T` schema lives in the shared MSSQL `isc` DB).
 * RefreshDatabase runs `migrate:fresh` (drops all tables), so they can never run
 * safely against the real database, and they cannot build a schema on SQLite.
 *
 * They are skipped (not deleted) until a dedicated test database exists. The
 * original assertions remain in git history. The previous `uses(Tests\TestCase::class, ...)`
 * call here also broke Pest's suite build (it conflicts with the Pest.php
 * `->in('Feature')` binding), which is why the whole suite could not run.
 */

it('customer cart controller tests are quarantined pending a safe test database', function () {
    $this->markTestSkipped('Quarantined: needs migrations / a dedicated test DB (see file header).');
});
