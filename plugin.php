<?php

use Backup\Archives;

return static function (PluginContext $plugin): void {
    $archives = static fn(): Archives => new Archives(
        $plugin->path('site_path'),
        getenv('MOTORCHK_BACKUP_DIR') ?: (Env::get('MOTORCHK_BACKUP_DIR') ?: null),
    );

    $plugin->adminRoute('GET', '/', static function (PluginRequest $request, PluginContext $plugin) use ($archives): void {
        try {
            $storage = $archives();
            $backups = $storage->listing();
            $directory = $storage->directory();
            $error = null;
        } catch (Throwable $e) {
            $backups = [];
            $directory = null;
            $error = $e->getMessage();
        }
        $plugin->render('admin', compact('backups', 'directory', 'error'), ['title' => 'Резервные копии']);
    });

    $plugin->adminRoute('POST', '/create', static function (PluginRequest $request, PluginContext $plugin) use ($archives): void {
        try {
            set_time_limit(0);
            $archives()->create();
            header('Location: ' . $plugin->adminUrl('/') . '?created=1', true, 303);
        } catch (Throwable $e) {
            http_response_code(500);
            $plugin->render('admin', [
                'backups' => [], 'directory' => null, 'error' => 'Копия не создана: ' . $e->getMessage(),
            ], ['title' => 'Резервные копии']);
        }
    });

    $plugin->adminRoute('GET', '/download/{name}', static function (PluginRequest $request, PluginContext $plugin) use ($archives): void {
        try {
            $name = (string) $request->param('name');
            $file = $archives()->file($name);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '.zip"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: private, no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            readfile($file);
        } catch (Throwable $e) {
            $plugin->jsonError('Архив недоступен', 404);
        }
    });

    $plugin->adminRoute('POST', '/delete/{name}', static function (PluginRequest $request, PluginContext $plugin) use ($archives): void {
        try {
            $archives()->delete((string) $request->param('name'));
            header('Location: ' . $plugin->adminUrl('/') . '?deleted=1', true, 303);
        } catch (Throwable $e) {
            $plugin->jsonError('Не удалось удалить архив: ' . $e->getMessage(), 422);
        }
    });
};
