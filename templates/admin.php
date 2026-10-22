<?php
/** @var PluginContext $plugin */
/** @var list<array{name: string, size: int}> $backups */
/** @var ?string $directory */
/** @var ?string $error */
$e = [PluginContext::class, 'e'];
?>
<div class="backup-admin">
    <h1 class="backup-h1">Резервные копии сайта</h1>
    <p class="backup-muted">Сохраните файлы сайта для переноса на другой хостинг. Ядро <code>core/</code> в архив не входит.</p>

    <?php if ($error !== null): ?><p class="backup-notice backup-notice--error" role="alert"><?= $e($error) ?></p><?php endif; ?>
    <?php if ($error === null): ?>
        <?php if (isset($_GET['created'])): ?><p class="backup-notice backup-notice--ok" role="status">Копия создана.</p><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><p class="backup-notice backup-notice--ok" role="status">Копия удалена.</p><?php endif; ?>

        <section class="backup-panel" aria-labelledby="backup-create-title">
            <h2 class="backup-h2" id="backup-create-title">Создать копию</h2>
            <p class="backup-muted">В ZIP войдут настройки, контент, медиафайлы, темы, плагины и их данные. Создание большой копии может занять несколько минут.</p>
            <form method="post" action="<?= $e($plugin->adminUrl('/create')) ?>">
                <?= $plugin->csrfField() ?>
                <button class="backup-btn backup-btn--primary" type="submit">Создать резервную копию</button>
            </form>
        </section>

        <section class="backup-panel" aria-labelledby="backup-list-title">
            <h2 class="backup-h2" id="backup-list-title">Готовые архивы</h2>
            <?php if ($backups === []): ?>
                <p class="backup-muted backup-empty">Архивов пока нет. Создайте первую резервную копию, чтобы скачать её здесь.</p>
            <?php else: ?>
                <ul class="backup-list">
                    <?php foreach ($backups as $archive): ?>
                        <li class="backup-row">
                            <div class="backup-file">
                                <span class="backup-name"><?= $e($archive['name']) ?>.zip</span>
                                <span class="backup-muted"><?= $e(number_format($archive['size'] / 1048576, 1, ',', ' ')) ?> МиБ</span>
                            </div>
                            <div class="backup-actions">
                                <a class="backup-btn backup-btn--small" href="<?= $e($plugin->adminUrl('/download/' . $archive['name'])) ?>">Скачать</a>
                                <form method="post" action="<?= $e($plugin->adminUrl('/delete/' . $archive['name'])) ?>" onsubmit="return confirm('Удалить эту резервную копию?')">
                                    <?= $plugin->csrfField() ?>
                                    <button class="backup-btn backup-btn--small backup-btn--danger" type="submit">Удалить</button>
                                </form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="backup-panel backup-panel--info" aria-labelledby="backup-storage-title">
            <h2 class="backup-h2" id="backup-storage-title">Хранение и перенос</h2>
            <p>Архив содержит <code>.env</code>, настройки и закрытые данные. Он не зашифрован: храните скачанный файл в безопасном месте и не публикуйте его.</p>
            <?php if ($directory !== null): ?><p class="backup-muted">Папка на сервере (вне сайта): <code class="backup-path"><?= $e($directory) ?></code></p><?php endif; ?>
            <p class="backup-muted">Для восстановления разверните совместимое ядро на новом хостинге и распакуйте ZIP в корень нового сайта, не поверх работающего сайта.</p>
        </section>
    <?php endif; ?>
</div>
