<?php

declare(strict_types=1);

/**
 * Apply database migrations.
 *
 *   php bin/migrate.php             apply everything pending
 *   php bin/migrate.php --status    show what is applied and what is not
 *   php bin/migrate.php --dry-run   name the pending migrations, change nothing
 *
 * On the server this runs over SSH or cPanel's Terminal from
 * /home/reshiftmanager/resm-app/, using the credentials in
 * config/config.local.php. It is never reachable over the web.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var Resm\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';

$options = array_slice($argv, 1);
$status  = in_array('--status', $options, true);
$dryRun  = in_array('--dry-run', $options, true);

$migrator = new Resm\Migrator($app->db(), $app->root . '/db/migrations');

try {
    if ($status) {
        $migrator->ensureRegistry();
        $applied = $migrator->applied();

        foreach ($migrator->available() as $version => $file) {
            $name = basename($file);
            if (isset($applied[$version])) {
                printf("  applied  %-40s %s UTC\n", $name, $applied[$version]['applied_at']);
            } else {
                printf("  PENDING  %s\n", $name);
            }
        }

        foreach ($migrator->drift() as $problem) {
            fwrite(STDERR, "  WARNING  {$problem}\n");
        }

        exit(0);
    }

    $applied = $migrator->migrate(static function (string $line): void {
        echo '  ', $line, "\n";
    }, $dryRun);

    if ($applied === []) {
        echo "Database is up to date.\n";
        exit(0);
    }

    printf("%s %d migration%s.\n", $dryRun ? 'Would apply' : 'Applied', count($applied), count($applied) === 1 ? '' : 's');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nMigration failed.\n" . $e->getMessage() . "\n");
    exit(1);
}
