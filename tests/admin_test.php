<?php

declare(strict_types=1);

use Resm\Auth\Role;

/**
 * The seeded master administrator (migration 003).
 */
test('the master admin account is seeded', function (): void {
    $row = testDb()->one(
        "SELECT member_id, role, is_active FROM `user` WHERE member_id = '987654321'"
    );

    assertTrue($row !== null, 'no account with Member ID 987654321 - did migration 003 run?');
    assertSame(Role::Admin->value, (string) $row['role']);
    assertSame(1, (int) $row['is_active']);
});

/**
 * The decision this guards is the reason the account ships locked.
 *
 * This repository is public and a PIN is four digits: every one of the 10,000
 * can be tried offline in about half an hour at the cost this application
 * uses. Committing a hash would publish the credential, and git would keep it
 * long after the PIN was changed. The login rate limit is no defence, because
 * an offline crack never touches the login form.
 *
 * So no migration may carry a password hash. If one ever does, this fails
 * before it reaches a public branch.
 */
test('no migration carries a password hash', function (): void {
    $offenders = [];

    foreach (glob(dirname(__DIR__) . '/db/migrations/*.sql') ?: [] as $file) {
        $sql = (string) file_get_contents($file);
        // bcrypt, and the argon2 forms, in case the algorithm ever changes.
        if (preg_match('/\$2[aby]\$\d\d\$|\$argon2(id|i|d)\$/', $sql) === 1) {
            $offenders[] = basename($file);
        }
    }

    assertSame([], $offenders, 'these migrations contain a password hash');
});

test('the seeded PIN placeholder cannot match any PIN', function (): void {
    // password_verify() returns false for a value that is not a hash, rather
    // than erroring, which is what keeps the account safely unusable until
    // bin/set-admin-pin.php runs.
    foreach (['9715', '1234', '0000', ''] as $pin) {
        assertTrue(!password_verify($pin, '!no-pin-set'), "'{$pin}' matched the placeholder");
    }
});
