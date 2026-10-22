<?php
declare(strict_types=1);

use Backup\Archives;

if (PHP_SAPI !== 'cli' || $argc !== 2 || !str_starts_with($argv[1], '/')) {
    fwrite(STDERR, "Usage: php create.php /absolute/path/to/site\n");
    exit(2);
}

require dirname(__DIR__) . '/lib/Backup/Archives.php';
try {
    $envFile = rtrim($argv[1], '/') . '/core/includes/Env.php';
    if (!is_file($envFile)) {
        throw new RuntimeException('Не найдено ядро motorchk (core/includes/Env.php)');
    }
    require $envFile;
    Env::load(rtrim($argv[1], '/') . '/.env');
    $archive = new Archives($argv[1], getenv('MOTORCHK_BACKUP_DIR') ?: (Env::get('MOTORCHK_BACKUP_DIR') ?: null));
    $name = $archive->create();
    fwrite(STDOUT, $archive->file($name) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
