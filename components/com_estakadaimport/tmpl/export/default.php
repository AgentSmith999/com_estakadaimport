<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$profileId = Factory::getApplication()->input->getInt('export_profile', 0);
$model = $this->getModel('Export');
$items = $model->getExportData($profileId);
?>

<!-- Селект для выбора профиля -->
<select id="export_profile" name="export_profile">
    <option value="1">Профиль 1</option>
    <option value="2">Профиль 2</option>
</select>

<!-- Контейнер для динамической таблицы (оставляем как было) -->
<div id="dynamic-table-container">
    <?php include __DIR__ . '/default_table.php'; ?>
</div>

<!-- AJAX-обработчик для обновления таблицы -->
<script>
document.getElementById('export_profile').addEventListener('change', function() {
    const profileId = this.value;
    fetch('index.php?option=com_estakadaimport&task=export.loadTable&format=raw&profile=' + profileId)
        .then(response => response.text())
        .then(html => {
            document.getElementById('dynamic-table-container').innerHTML = html;
        });
});
</script>