<?php

declare(strict_types=1);

/**
 * Set the PIN on an administrator account.
 *
 *   php bin/set-admin-pin.php                 the master admin (987654321)
 *   php bin/set-admin-pin.php 123456          some other member ID
 *
 * The master admin is seeded locked by migration 003, because this repository
 * is public and a committed 4-digit PIN hash is a published credential. This
 * is how the PIN gets set: typed once, on the server, never written down in
 * git and never passed as an argument where a shell history would keep it.
 *
 * Run it again whenever the PIN needs resetting from outside the app.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Resm\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

use Resm\Auth\Pin;
use Resm\Auth\Role;

$memberId = $argv[1] ?? '987654321';

/**
 * Read a line without echoing it back. cPanel's Terminal is a real tty, so
 * stty works there; if it is not available the prompt still functions and only
 * the shoulder-surfing protection is lost.
 */
function readSecret(string $prompt): string
{
    echo $prompt;

    $hidden = false;
    if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY, 'win') === false) {
        $hidden = shell_exec('stty -echo 2>/dev/null') !== null || true;
        @shell_exec('stty -echo 2>/dev/null');
    }

    $line = fgets(STDIN);

    if ($hidden) {
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }

    return trim($line === false ? '' : $line);
}

try {
    $db = $app->db();

    $user = $db->one(
        'SELECT id, member_id, first_name, last_name, role, is_active
         FROM `user` WHERE member_id = :member_id',
        ['member_id' => $memberId]
    );

    if ($user === null) {
        fwrite(STDERR, "No account with Member ID {$memberId}.\n"
            . "Has 'php bin/migrate.php' been run?\n");
        exit(1);
    }

    if (Role::from((string) $user['role']) !== Role::Admin) {
        // This script exists to unlock the seeded administrator, not to become
        // a way to set anyone's PIN from the command line. Officers reset a
        // committeeman's PIN from the roster screen, where it is audited
        // against them.
        fwrite(STDERR, "{$memberId} is not an administrator. Use the officer roster screen instead.\n");
        exit(1);
    }

    printf(
        "Setting the PIN for %s %s (Member ID %s, %s).\n",
        (string) $user['first_name'],
        (string) $user['last_name'],
        (string) $user['member_id'],
        (int) $user['is_active'] === 1 ? 'active' : 'INACTIVE'
    );

    $pin = readSecret('New 4-digit PIN: ');
    if (!Pin::isValid($pin)) {
        fwrite(STDERR, "A PIN is exactly four digits.\n");
        exit(1);
    }

    $again = readSecret('Again: ');
    if (!hash_equals($pin, $again)) {
        fwrite(STDERR, "The two PINs did not match.\n");
        exit(1);
    }

    $cost = $app->config->int('auth.pin_cost', 11);
    $userId = (int) $user['id'];
    $now = gmdate('Y-m-d H:i:s');

    $db->transaction(function (Resm\Database $db) use ($userId, $pin, $cost, $now): void {
        $db->execute(
            'UPDATE `user` SET pin_hash = :hash, pin_changed_at = :now WHERE id = :id',
            ['hash' => Pin::hash($pin, $cost), 'now' => $now, 'id' => $userId]
        );

        // Same rule as changing a PIN in the app: every other session for this
        // account stops working. There is no current device to keep here.
        $db->execute(
            'UPDATE auth_token SET revoked_at = :now WHERE user_id = :id AND revoked_at IS NULL',
            ['now' => $now, 'id' => $userId]
        );
    });

    (new Resm\AuditLog($db))->record($userId, 'pin_set_via_cli', 'user', $userId);

    echo "PIN set. Any existing sessions for this account have been signed out.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nCould not set the PIN.\n" . $e->getMessage() . "\n");
    exit(1);
}
