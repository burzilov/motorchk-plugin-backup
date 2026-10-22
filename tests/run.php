<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/lib/Backup/Archives.php';

use Backup\Archives;

function check(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    echo "ok: $name\n";
}

function rmTree(string $path): void
{
    if (!is_dir($path)) { @unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) rmTree($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($path);
}

$parent = sys_get_temp_dir() . '/motorchk-backup-test-' . bin2hex(random_bytes(6));
$site = $parent . '/site'; $restore = $parent . '/restore';
mkdir($site . '/core', 0700, true);
try {
    file_put_contents($site . '/core/VERSION', '0.4.0');
    file_put_contents($site . '/core/exclude.php', '<?php echo "CORE";');
    mkdir($site . '/content/deep', 0700, true);
    mkdir($site . '/plugins/example', 0700, true);
    mkdir($site . '/plugin-data/example', 0700, true);
    mkdir($site . '/media/empty', 0700, true);
    file_put_contents($site . '/content/deep/page.md', "Привет\n");
    file_put_contents($site . '/plugins/example/plugin.json', '{"id":"example"}');
    file_put_contents($site . '/plugin-data/example/secrets.json', '{"token":"private"}');
    file_put_contents($site . '/.env', 'ADMIN_PASSWORD_HASH=private');
    file_put_contents($site . '/.htaccess', 'RewriteEngine On');
    file_put_contents($site . '/index.php', '<?php require __DIR__ . "/core/includes/bootstrap.php";');
    $archives = new Archives($site);
    check(!str_starts_with($archives->directory(), $site . '/'), 'Хранилище вне сайта');
    check((fileperms($archives->directory()) & 0077) === 0, 'Папка закрыта от других пользователей');
    $name = $archives->create();
    $file = $archives->file($name);
    check((fileperms($file) & 0077) === 0 && count($archives->listing()) === 1, 'Архив закрыт и найден в списке');
    $zip = new ZipArchive();
    check($zip->open($file, ZipArchive::CHECKCONS) === true, 'ZIP читается');
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) $entries[] = $zip->getNameIndex($i);
    check(!array_filter($entries, static fn($p) => $p === 'core/' || str_starts_with($p, 'core/')), 'Ядро исключено');
    check(in_array('.env', $entries, true) && in_array('.htaccess', $entries, true)
        && in_array('plugin-data/example/secrets.json', $entries, true)
        && in_array('media/empty/', $entries, true), 'Скрытые файлы, данные и пустые папки включены');
    $comment = json_decode($zip->getArchiveComment(), true, 512, JSON_THROW_ON_ERROR);
    check($comment['format'] === 'motorchk/site-backup@1' && $comment['core_version'] === '0.4.0', 'Версия ядра записана');
    mkdir($restore . '/core', 0700, true);
    file_put_contents($restore . '/core/VERSION', '0.4.0');
    check($zip->extractTo($restore), 'Распаковка поверх ядра');
    $zip->close();
    check(file_get_contents($restore . '/.env') === file_get_contents($site . '/.env')
        && file_get_contents($restore . '/content/deep/page.md') === file_get_contents($site . '/content/deep/page.md')
        && file_get_contents($restore . '/plugin-data/example/secrets.json') === file_get_contents($site . '/plugin-data/example/secrets.json'), 'Данные восстановлены без изменений');
    check(!is_file($restore . '/core/exclude.php') && is_file($restore . '/core/VERSION'), 'Восстановленное ядро не заменено');
    foreach (['../.env', 'backup-20000101T000000Z-deadbeef/../etc/passwd'] as $bad) {
        try { $archives->file($bad); throw new RuntimeException('Accepted unsafe name'); }
        catch (RuntimeException $e) { check($e->getMessage() !== 'Accepted unsafe name', 'Небезопасный путь отклонён'); }
    }
    try { new Archives($site, $site . '/plugin-data/backups'); throw new RuntimeException('Accepted public storage'); }
    catch (RuntimeException $e) { check($e->getMessage() !== 'Accepted public storage', 'Хранилище в сайте отклонено'); }
    symlink($site . '/content/deep/page.md', $site . '/content/link.md');
    try { $archives->create(); throw new RuntimeException('Accepted symlink'); }
    catch (RuntimeException $e) { check($e->getMessage() !== 'Accepted symlink', 'Ссылка в данных отклонена'); }
    unlink($site . '/content/link.md');
    check(count($archives->listing()) === 1, 'Неудачная попытка не создала архив');
    $archives->delete($name);
    check($archives->listing() === [], 'Удаление архива');
    echo "ALL OK\n";
} finally {
    rmTree($parent);
}
