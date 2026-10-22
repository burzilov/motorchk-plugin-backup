<?php
/** @var PluginContext $plugin */
$escape = [PluginContext::class, 'e'];
?>
<section style="max-width: 58rem; margin: 2rem auto;">
    <h1>Резервные копии сайта</h1>
    <p>Архив содержит файлы сайта, включая настройки и закрытые данные. Ядро <code>core/</code> в него не входит. Храните архив в надёжном месте и никому не передавайте без необходимости.</p>
    <?php if ($directory !== null): ?>
        <p>Место хранения вне сайта: <code><?= $escape($directory) ?></code></p>
    <?php endif; ?>
    <?php if ($error !== null): ?><p role="alert" style="color:#b42318"><?= $escape($error) ?></p><?php endif; ?>
    <?php if ($error === null): ?>
        <?php if (isset($_GET['created'])): ?><p role="status">Копия создана.</p><?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?><p role="status">Копия удалена.</p><?php endif; ?>
        <form method="post" action="<?= $escape($plugin->adminUrl('/create')) ?>">
            <?= $plugin->csrfField() ?>
            <button type="submit">Создать резервную копию</button>
        </form>
        <h2>Архивы</h2>
        <?php if ($backups === []): ?><p>Архивов пока нет.</p><?php endif; ?>
        <ul>
        <?php foreach ($backups as $archive): ?>
            <li>
                <a href="<?= $escape($plugin->adminUrl('/download/' . $archive['name'])) ?>"><?= $escape($archive['name']) ?>.zip</a>
                (<?= $escape(number_format($archive['size'] / 1048576, 1, ',', ' ')) ?> МиБ)
                <form method="post" action="<?= $escape($plugin->adminUrl('/delete/' . $archive['name'])) ?>" style="display:inline" onsubmit="return confirm('Удалить эту резервную копию?')">
                    <?= $plugin->csrfField() ?>
                    <button type="submit">Удалить</button>
                </form>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
