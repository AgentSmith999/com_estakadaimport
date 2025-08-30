<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

HTMLHelper::_('formbehavior.chosen', 'select');

// Получаем объект документа
$document = Factory::getDocument();
$document->addStyleSheet(Uri::root(true) . '/components/com_estakadaimport/assets/css/estakadaimport.css');

// Подключаем jQuery и SheetJS
HTMLHelper::_('jquery.framework');
$wa = $document->getWebAssetManager();
$wa->registerAndUseScript(
    'com_estakadaimport.sheetjs', 
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
    [],
    ['async' => true]
);


?>

<div class="com-estakadaimport-export">
    <form action="<?php echo Route::_('index.php?option=com_estakadaimport&task=export.export'); ?>"
        method="post" name="adminForm" id="adminForm">

        <!-- Выбор профиля -->
        <div class="control-group">
            <label for="export_profile" class="control-label">Профиль экспорта:</label>
            <div class="controls">
                <select id="export_profile" name="export_profile" class="form-select">
                    <?php foreach ($this->profiles as $id => $title): ?>
                        <option value="<?php echo $id; ?>" 
                            <?php echo ($id == $this->selectedProfile) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Выбор категории -->
        <div class="control-group mt-3">
            <label for="category" class="control-label">Категория:</label>
            <div class="controls">
                <select id="category" name="category" class="form-select">
                    <option value="0">Все категории</option>
                    <?php foreach ($this->availableCategories as $id => $cat): ?>
                        <option value="<?php echo $id; ?>" 
                            <?php echo ($id == $this->selectedCategory) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Выбор производителя -->
        <div class="control-group mt-3">
            <label for="manufacturer" class="control-label">Производитель:</label>
            <div class="controls">
                <select id="manufacturer" name="manufacturer" class="form-select">
                    <option value="0">Все производители</option>
                    <?php foreach ($this->availableManufacturers as $id => $brand): ?>
                        <option value="<?php echo $id; ?>" 
                            <?php echo ($id == $this->selectedManufacturer) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($brand, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Кнопка -->
        <div class="form-actions mt-3">
            <button type="button" id="export-excel" class="btn btn-success">
                <span class="icon-download"></span> Экспорт в Excel
            </button>
        </div>

        <br>
        <br>
        <br>

        <!-- Таблица (default_имя.php — подшаблон, который можно загрузить через loadTemplate('имя')) -->
        <label for="export_profile" class="form-label fw-bold">Как будет выглядить excel файл:</label>
        <div id="dynamic-table-container" class="mt-3">
                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">Загрузка данных...</div>
                <?php else : ?>
                    <?php echo $this->loadTemplate('table'); ?>
                <?php endif; ?>
        </div>



        <input type="hidden" name="task" value="" />
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // AJAX-обновление таблицы
    // Обработка изменения обоих селекторов
    $('#export_profile, #manufacturer, #category').change(function() {
        const profileId = $('#export_profile').val();
        const manufacturerId = $('#manufacturer').val();
        const categoryId = $('#category').val();
        
        const $container = $('#dynamic-table-container');
        $container.html('<div class="text-center"><span class="icon-spinner icon-spin"></span> Загрузка...</div>');
        
        $.ajax({
            url: 'index.php?option=com_estakadaimport&task=export.loadTable&format=raw',
            data: { 
                profile: profileId,
                manufacturer: manufacturerId,
                category: categoryId // ← Новый параметр
            },
            cache: false
        })
        .done(function(html) {
            $container.html(html);
        })
        .fail(function(xhr) {
            console.error('AJAX Error:', xhr.responseText);
            $container.html('<div class="alert alert-danger">Ошибка загрузки</div>');
        });
    });

    // Экспорт в Excel через SheetJS
    $(document).on('click', '#export-excel', function() {
        if (typeof XLSX === 'undefined') {
            alert('Библиотека SheetJS не загрузилась. Обновите страницу.');
            return;
        }

        const table = document.getElementById('export-preview-table');
        if (!table) {
            alert('Таблица для экспорта не найдена!');
            return;
        }

        try {
            const wb = XLSX.utils.table_to_book(table);
            const profileName = $('#export_profile option:selected').text()
                .trim()
                .replace(/[^a-zа-яё0-9]/gi, '_');
            
            XLSX.writeFile(wb, `Товары_${profileName}_${new Date().toLocaleDateString('ru-RU')}.xls`);
        } catch (e) {
            console.error('Ошибка экспорта:', e);
            alert('Ошибка при создании Excel-файла: ' + e.message);
        }
    });
});
</script>