<?php

namespace Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * ZIP с путями относительно корня сайта: распаковывается поверх нового ядра.
 * Содержимое cache/ не копируется (папка остаётся пустой): кэш пересоздаётся ядром.
 * После каждой новой копии хранятся только последние $keep архивов плагина.
 */
final class Archives
{
    private const NAME = '/^backup-\d{8}T\d{6}Z-[a-f0-9]{8}$/D';
    public const KEEP = 10;
    /** Папка в корне сайта, содержимое которой не архивируется. */
    public const CACHE = 'cache';

    private string $site;
    private string $directory;
    private int $keep;

    /** Число хранимых копий из MOTORCHK_BACKUP_KEEP: пусто — KEEP, иначе целое ≥ 1. */
    public static function keepFrom(?string $value): int
    {
        if ($value === null || $value === '') {
            return self::KEEP;
        }
        if (!preg_match('/^[1-9][0-9]{0,3}$/D', $value)) {
            throw new RuntimeException('MOTORCHK_BACKUP_KEEP должен быть целым числом от 1 до 9999');
        }
        return (int) $value;
    }

    public function __construct(string $site, ?string $directory = null, int $keep = self::KEEP)
    {
        if ($keep < 1) {
            throw new RuntimeException('Нужно хранить хотя бы одну резервную копию');
        }
        $this->keep = $keep;
        $real = realpath($site);
        if ($real === false || !is_dir($real) || !is_dir($real . '/core')) {
            throw new RuntimeException('Не найден корень сайта с установленным core/');
        }
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Для резервных копий нужно расширение PHP zip');
        }
        $this->site = rtrim($real, '/');
        $requested = $directory ?? dirname($this->site) . '/motorchk-backups-' . basename($this->site);
        $requested = rtrim($requested, '/');
        if (!str_starts_with($requested, '/')) {
            throw new RuntimeException('Путь к резервным копиям должен быть абсолютным');
        }
        // Не принимаем символьную ссылку как конечную директорию.
        if (is_link($requested)) {
            throw new RuntimeException('Папка резервных копий не должна быть символьной ссылкой');
        }
        if (!is_dir($requested)) {
            $parent = realpath(dirname($requested));
            if ($parent === false || basename($requested) === '.' || basename($requested) === '..') {
                throw new RuntimeException('Родительская папка резервных копий не найдена');
            }
            $candidate = $parent . '/' . basename($requested);
        } else {
            $candidate = realpath($requested);
        }
        if ($candidate === false || $candidate === $this->site || str_starts_with($candidate, $this->site . '/')) {
            throw new RuntimeException('Резервные копии должны храниться за пределами сайта');
        }
        if (!is_dir($candidate)) {
            $old = umask(0077);
            try {
                if (!mkdir($candidate, 0700) && !is_dir($candidate)) {
                    throw new RuntimeException('Не удалось создать папку резервных копий');
                }
            } finally {
                umask($old);
            }
        }
        clearstatcache(true, $candidate);
        if ((fileperms($candidate) & 0077) !== 0 || !is_writable($candidate)) {
            throw new RuntimeException('Папка резервных копий должна быть доступна только владельцу и разрешать запись');
        }
        $this->directory = $candidate;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function keep(): int
    {
        return $this->keep;
    }

    /** @return list<array{name: string, size: int}> */
    public function listing(): array
    {
        $out = [];
        foreach (new FilesystemIterator($this->directory, FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getBasename('.zip');
            if ($entry->getExtension() === 'zip' && !$entry->isLink() && $entry->isFile() && preg_match(self::NAME, $name)) {
                $out[] = ['name' => $name, 'size' => $entry->getSize()];
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp($b['name'], $a['name']));
        return $out;
    }

    public function file(string $name): string
    {
        if (!preg_match(self::NAME, $name)) {
            throw new RuntimeException('Некорректное имя архива');
        }
        $path = $this->directory . '/' . $name . '.zip';
        if (is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Архив не найден');
        }
        return $path;
    }

    public function delete(string $name): void
    {
        $lock = $this->lock();
        try {
            if (!unlink($this->file($name))) {
                throw new RuntimeException('Не удалось удалить архив');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function create(): string
    {
        $lock = $this->lock();
        $temporary = null;
        $zip = null;
        try {
            $name = 'backup-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
            $temporary = $this->directory . '/.' . $name . '.partial';
            $final = $this->directory . '/' . $name . '.zip';
            $zip = new ZipArchive();
            $old = umask(0077);
            try {
                $opened = $zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL);
            } finally {
                umask($old);
            }
            if ($opened !== true) {
                throw new RuntimeException('Не удалось открыть временный ZIP');
            }
            $count = 0;
            $bytes = 0;
            $inventory = $this->inventory();
            foreach ($inventory as $relative => $info) {
                if ($info['dir']) {
                    if (!$zip->addEmptyDir($relative)) {
                        throw new RuntimeException('Не удалось добавить папку ' . $relative);
                    }
                } else {
                    if (!$zip->addFile($this->site . '/' . $relative, $relative)) {
                        throw new RuntimeException('Не удалось добавить файл ' . $relative);
                    }
                    $count++;
                    $bytes += $info['size'];
                }
            }
            if ($count === 0) {
                throw new RuntimeException('Сайт не содержит файлов для резервного копирования');
            }
            $version = is_file($this->site . '/core/VERSION') ? trim((string) file_get_contents($this->site . '/core/VERSION')) : null;
            $zip->setArchiveComment(json_encode([
                'format' => 'motorchk/site-backup@1', 'created_utc' => gmdate('c'),
                'core_version' => $version, 'files' => $count, 'bytes' => $bytes,
                'restore' => 'Extract into a site directory containing core/; do not expose the ZIP over HTTP.',
            ], JSON_THROW_ON_ERROR));
            $old = umask(0077);
            try {
                $closed = $zip->close();
            } finally {
                umask($old);
            }
            if (!$closed) {
                $zip = null;
                throw new RuntimeException('Не удалось завершить ZIP (проверьте свободное место и права)');
            }
            $zip = null;
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Не удалось ограничить доступ к архиву');
            }
            if ($this->inventory() !== $inventory) {
                throw new RuntimeException('Файлы сайта изменились во время копирования; повторите попытку');
            }
            $check = new ZipArchive();
            if ($check->open($temporary, ZipArchive::CHECKCONS) !== true || $check->numFiles < $count) {
                throw new RuntimeException('Не удалось проверить созданный архив');
            }
            $check->close();
            if (!rename($temporary, $final)) {
                throw new RuntimeException('Не удалось опубликовать готовый архив');
            }
            $this->rotate();
            return $name;
        } finally {
            if ($zip instanceof ZipArchive) $zip->close();
            if ($temporary !== null && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Удаляет архивы сверх последних $keep. Только в папке копий и только файлы с именем
     * backup-<UTC>-<hex>.zip (listing()); остальные файлы не трогаются. Вызывается под блокировкой.
     */
    private function rotate(): void
    {
        foreach (array_slice($this->listing(), $this->keep) as $old) {
            if (!unlink($this->directory . '/' . $old['name'] . '.zip')) {
                throw new RuntimeException('Копия создана, но старый архив не удалён: ' . $old['name']);
            }
        }
    }

    /** @return array<string, array{dir: bool, size: int, mtime: int}> */
    private function inventory(): array
    {
        $out = [];
        foreach (new FilesystemIterator($this->site, FilesystemIterator::SKIP_DOTS) as $top) {
            if ($top->getFilename() === 'core') continue;
            $this->record($top, $top->getFilename(), $out);
            if (!$top->isDir() || $top->getFilename() === self::CACHE) continue;
            $walk = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($top->getPathname(), FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($walk as $entry) {
                $relative = substr($entry->getPathname(), strlen($this->site) + 1);
                $this->record($entry, $relative, $out);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param array<string, array{dir: bool, size: int, mtime: int}> $out */
    private function record(\SplFileInfo $entry, string $relative, array &$out): void
    {
        if ($entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
            throw new RuntimeException('Неподдерживаемый файл или ссылка: ' . $relative);
        }
        if ($entry->isFile() && !$entry->isReadable()) {
            throw new RuntimeException('Нет доступа к файлу: ' . $relative);
        }
        $out[$relative] = ['dir' => $entry->isDir(), 'size' => $entry->isDir() ? 0 : $entry->getSize(), 'mtime' => $entry->getMTime()];
    }

    /** @return resource */
    private function lock()
    {
        $old = umask(0077);
        try {
            $handle = fopen($this->directory . '/.backup.lock', 'c');
        } finally {
            umask($old);
        }
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new RuntimeException('Другая операция с резервными копиями ещё выполняется');
        }
        chmod($this->directory . '/.backup.lock', 0600);
        return $handle;
    }
}
