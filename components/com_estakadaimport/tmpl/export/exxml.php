<?php

/**
 * @package     Joomla.Site
 * @subpackage  com_users
 *
 * @copyright   (C) 2009 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

/** @var Joomla\Component\Users\Site\View\Profile\HtmlView $this */

HTMLHelper::_('bootstrap.tooltip', '.hasTooltip');

// Load user_profile plugin language
$lang = $this->getLanguage();
$lang->load('plg_user_profile', JPATH_ADMINISTRATOR);

/** @var Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $this->getDocument()->getWebAssetManager();
$wa->useScript('keepalive')
    ->useScript('form.validate');

// Получаем объект базы данных Joomla
$db = JFactory::getDbo();

// 1. Получаем выбранный профиль (по умолчанию первый из списка)
$selectedProfile = JFactory::getApplication()->input->getInt('export_profile', 0);
// Если параметр не передан, берем первый из списка
if (!$selectedProfile) {
    $db = JFactory::getDbo();
    $query = $db->getQuery(true)
        ->select('MIN(virtuemart_custom_id)')
        ->from('#__virtuemart_customs')
        ->where('field_type = ' . $db->quote('G'));
    $selectedProfile = $db->setQuery($query)->loadResult();
}

// 2. Получаем список профилей (групп полей)
$queryProfiles = $db->getQuery(true);
$queryProfiles->select($db->quoteName(array('virtuemart_custom_id', 'custom_title')))
             ->from($db->quoteName('#__virtuemart_customs'))
             ->where($db->quoteName('field_type') . ' = ' . $db->quote('G'));

$db->setQuery($queryProfiles);
$profiles = $db->loadAssocList('virtuemart_custom_id', 'custom_title');

// Подключаем jQuery
JHtml::_('jquery.framework');

?>
<div class="com-users-profile__edit profile-edit">
    <?php if ($this->params->get('show_page_heading')) : ?>
        <div class="page-header"> Экспорт товаров </div>
    <?php endif; ?>
    <div class="row">
            <!-- Select с обработчиком изменения -->
            <div class="mb-3">
                <label for="export_profile" class="form-label fw-bold">Профиль экспорта:</label>
                <select name="export_profile" id="export_profile" class="form-select">
                    <?php foreach ($profiles as $id => $title): ?>
                        <option value="<?php echo $id; ?>" <?php echo ($id == $selectedProfile) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div>
            <button id="export-excel" class="btn btn-success mt-3">
                <i class="icon-download"></i> Экспорт в Excel
            </button>
        </div>
        <label for="export_profile" class="form-label fw-bold">Как будет выглядить excel файл:</label>
        <!-- Начало контейнера таблицы -->
        <div id="dynamic-table-container">
            <?php include __DIR__ . '/exxml_table.php'; ?>
        </div>
        <!-- end table container -->
</div>

<!-- AJAX-скрипт -->
<script>
jQuery(document).ready(function($) {
    $('#export_profile').change(function() {
        const profileId = $(this).val();
        const baseUrl = '<?php echo JUri::base(true); ?>';
        const itemId = <?php echo JFactory::getApplication()->input->getInt('Itemid'); ?>;
        
        // Показываем индикатор загрузки
        $('#dynamic-table-container').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i> Загрузка...</div>');
        
        // Формируем URL для запроса
        const ajaxUrl = baseUrl + '/index.php?option=com_users&view=profile&layout=exxml&Itemid=' + itemId + '&export_profile=' + profileId;
        
        // AJAX-запрос
        $.ajax({
            url: ajaxUrl,
            type: 'GET',
            cache: false,
            success: function(data) {
                // Находим начало таблицы
                const tableStart = data.indexOf('<div id="dynamic-table-container">');
                if (tableStart === -1) {
                    $('#dynamic-table-container').html('<div class="alert alert-danger">Не удалось найти таблицу в ответе</div>');
                    return;
                }
                
                // Находим конец таблицы
                const tableEnd = data.indexOf('</div><!-- end table container -->', tableStart);
                if (tableEnd === -1) {
                    $('#dynamic-table-container').html('<div class="alert alert-danger">Неполные данные таблицы</div>');
                    return;
                }
                
                // Извлекаем таблицу
                const tableHtml = data.substring(tableStart, tableEnd + 31); // +31 для закрывающего тега
                $('#dynamic-table-container').html(tableHtml);
                $('#form_export_profile').val(profileId);
            },
            error: function(xhr) {
                console.error('AJAX Error:', xhr.responseText);
                $('#dynamic-table-container').html(
                    '<div class="alert alert-danger">Ошибка сервера: ' + xhr.status + ' ' + xhr.statusText + '</div>'
                );
            }
        });
    });
});
</script>



<!-- Подключаем библиотеку SheetJS -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
document.getElementById('export-excel').addEventListener('click', function() {
    const table = document.querySelector('#dynamic-table-container table');
    const wb = XLSX.utils.table_to_book(table);
    
    // Форматируем имя файла
    const profileName = $('#export_profile option:selected').text()
        .trim()                         // Убираем пробелы по краям
        .replace(/\s+/g, ' ');          // Заменяем множественные пробелы на один
    
    const fileName = `Товары_${profileName}_${new Date().toLocaleDateString('ru-RU')}.xls`;
    
    // Экспорт в XLS (старый формат)
    XLSX.writeFile(wb, fileName, { bookType: 'xls' });
});
</script>
