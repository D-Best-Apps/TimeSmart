<?php
/**
 * TimeSmart database migration runner.
 *
 * Applies any *.sql files in the migrations directory that haven't been applied
 * yet, tracked in a `schema_migrations` table. Idempotent: already-applied
 * migrations are skipped, so it's safe to run on every deploy.
 *
 * Designed to run INSIDE the app container (it has mysqli + the DB_* env vars):
 *   docker cp deploy/database/migrations  <container>:/tmp/ts_migrations
 *   docker cp deploy/scripts/run_migrations.php <container>:/tmp/run_migrations.php
 *   docker exec <container> php /tmp/run_migrations.php /tmp/ts_migrations
 *
 * Exit code 0 = success (including "nothing to do"); non-zero = a migration failed.
 */

$dir = $argv[1] ?? (__DIR__ . '/../database/migrations');
$dir = rtrim($dir, '/');

$host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '');
$name = getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? '');
$user = getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? '');
$pass = getenv('DB_PASS') ?: ($_ENV['DB_PASS'] ?? '');

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, $name);
if ($conn->connect_error) {
    fwrite(STDERR, "✗ DB connection failed: {$conn->connect_error}\n");
    exit(1);
}
$conn->set_charset('utf8mb4');

// Tracking table
$conn->query("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `filename` VARCHAR(191) NOT NULL,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Already-applied set
$applied = [];
if ($res = $conn->query("SELECT filename FROM schema_migrations")) {
    while ($row = $res->fetch_assoc()) {
        $applied[$row['filename']] = true;
    }
    $res->free();
}

$files = glob($dir . '/*.sql');
if ($files === false) {
    fwrite(STDERR, "✗ Cannot read migrations directory: {$dir}\n");
    exit(1);
}
sort($files, SORT_STRING); // dated filenames sort chronologically

$ran = 0;
foreach ($files as $path) {
    $file = basename($path);
    if (isset($applied[$file])) {
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        echo "• skip (empty): {$file}\n";
        $record = $conn->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
        $record->bind_param("s", $file);
        $record->execute();
        continue;
    }

    echo "→ applying {$file} ... ";
    $ok = $conn->multi_query($sql);
    if ($ok) {
        // Drain all result sets so the connection is reusable
        do {
            if ($r = $conn->store_result()) {
                $r->free();
            }
        } while ($conn->more_results() && $conn->next_result());
    }
    if (!$ok || $conn->errno) {
        echo "FAILED\n";
        fwrite(STDERR, "✗ {$file}: ({$conn->errno}) {$conn->error}\n");
        exit(1);
    }

    $record = $conn->prepare("INSERT INTO schema_migrations (filename) VALUES (?)");
    $record->bind_param("s", $file);
    $record->execute();
    echo "ok\n";
    $ran++;
}

echo $ran === 0 ? "✓ Database already up to date (no pending migrations).\n"
               : "✓ Applied {$ran} migration(s).\n";
exit(0);
