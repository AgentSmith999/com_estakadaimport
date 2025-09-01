<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;


HTMLHelper::_('formbehavior.chosen', 'select');

$app = Factory::getApplication();

// Подключаем CSS и JS
$document = Factory::getDocument();
$document->addStyleSheet(Uri::root(true) . '/components/com_estakadaimport/assets/css/estakadaimport.css');
$document->addScript(Uri::root(true) . '/components/com_estakadaimport/assets/js/import.js');
$document->addScript(Uri::root(true) . '/components/com_estakadaimport/assets/js/progress.js');

// Используем данные из view
$profiles = $this->profiles;
$defaultProfileId = $this->defaultProfileId;

// Получаем список продавцов если пользователь SuperUser
$vendorsList = [];
$isSuperUser = Factory::getUser()->authorise('core.admin');
if ($isSuperUser) {
    $vendorsList = $this->getModel()->getVendorsList();
}
?>

<div class="com-estakadaimport-import">
    <h2 class="mb-4">Импорт товаров из Excel</h2>

    <?php if ($isSuperUser): ?>
    <div class="alert alert-info mb-4">
        <strong>Режим SuperUser</strong> - вы можете импортировать товары от имени любого продавца
    </div>
    <?php endif; ?>

    <form action="<?php echo Route::_('index.php?option=com_estakadaimport&task=import.upload'); ?>" 
          method="post" name="adminForm" id="adminForm" class="form-horizontal" enctype="multipart/form-data">

        <!-- Выбор продавца (только для SuperUser) -->
        <?php if ($isSuperUser && !empty($vendorsList)): ?>
        <div class="control-group">
            <label for="selected_vendor" class="control-label">Импортировать от имени:</label>
            <div class="controls">
                <select id="selected_vendor" name="selected_vendor" class="form-select">
                    <option value="">-- Выберите продавца --</option>
                    <?php foreach ($vendorsList as $vendor): ?>
                        <option value="<?php echo $vendor->virtuemart_vendor_id; ?>">
                            <?php echo htmlspecialchars($vendor->company ?: $vendor->user_name, ENT_QUOTES, 'UTF-8'); ?>
                            (ID: <?php echo $vendor->virtuemart_vendor_id; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Выберите продавца, от имени которого будет выполнен импорт</small>
            </div>
        </div>
        <?php endif; ?>

        <!-- Чекбокс "Только обновить цены" -->
        <div class="control-group mb-3">
            <div class="controls">
                <label class="checkbox">
                    <input type="checkbox" name="update_prices_only" id="update_prices_only" value="1" />
                    Только обновить цены
                </label>
                <small class="text-muted">При активации обновляются только цены существующих товаров (артикул, цена, модификатор цены)</small>
            </div>
        </div>

        <!-- Выбор профиля -->
        <div class="control-group group-profile">
            <label for="import_profile" class="control-label">Профиль импорта:</label>
            <div class="controls">
                <select id="import_profile" name="import_profile" class="form-select">
                    <?php foreach ($profiles as $id => $title): ?>
                        <option value="<?php echo $id; ?>" 
                            <?php echo ($id == $defaultProfileId) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Выберите группу товаров для импорта настраиваемых полей</small>
            </div>
        </div>  

        <!-- Загрузка файла -->
        <div class="control-group mt-3">
            <label class="control-label" for="xlsfile">Файл Excel:</label>
            <div class="controls">
                <input type="file" name="xlsfile" id="xlsfile" 
                       accept=".xls,.xlsx, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel" required />
                <small class="text-muted">Поддерживаются файлы .xls и .xlsx</small>
            </div>
        </div>

        <!-- Кнопки -->
        <div class="form-actions mt-4">
            <button type="submit" class="btn btn-primary btn-lg">
                <span class="icon-upload"></span> Импортировать
            </button>
        </div>

        <?php echo HTMLHelper::_('form.token'); ?>
    </form>

    <!-- HTML структура для отображения прогресса -->
    <div id="importProgress" style="display: none;">
        <h4>Импорт данных</h4>
        
        <div class="import-stats">
            <div class="stat-item">
                <div>Строк</div>
                <div class="stat-value" id="rowCount">0</div>
            </div>
            <div class="stat-item">
                <div>Изображений</div>
                <div class="stat-value" id="totalCount">0</div>
            </div>
            <div class="stat-item">
                <div>Осталось</div>
                <div class="stat-value" id="timeRemaining">0</div>
            </div>
        </div>
        
        <div class="progress">
            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                 role="progressbar" style="width: 0%"></div>
        </div>
        
        <div class="import-status">
            <p><strong>Обработано изображений:</strong> <span id="processedCount">0</span> / <span id="totalCount2">0</span></p>
            <p><strong>Текущее изображение:</strong> <span id="currentImage">-</span></p>
        </div>
        
        <button id="cancelImport" class="btn btn-danger btn-cancel">
            <span class="icon-cancel"></span> Отменить импорт
        </button>
    </div>

    <div id="importComplete" style="display: none;" class="alert alert-success">
        <h4>✅ Импорт завершен успешно!</h4>
        <p>Обработано товаров: <span id="completedCount">0</span></p>
        <p>Обработано изображений: <span id="completedImages">0</span></p>
        <div class="mt-3">
            <button id="newImport" class="btn btn-primary ml-2">
                <span class="icon-plus"></span> Новый импорт
            </button>
        </div>
    </div>

    <!-- Блок ошибки несоответствия профиля -->
    <div id="profileMismatchError" style="display: none;" class="alert alert-danger">
        <h4>❌ Несоответствие профиля!</h4>
        <p>Вы выбрали один профиль товаров, но импортируете Excel файл с заголовками для другого профиля.</p>
        <p>Пожалуйста:</p>
        <ul>
            <li>Проверьте, что выбрали правильный профиль в настройках импорта</li>
            <li>Убедитесь, что Excel файл соответствует выбранному профилю товаров</li>
            <li>Скачайте шаблон Excel для выбранного профиля</li>
        </ul>
        <div class="mt-3">
            <button id="reloadPageProfile" class="btn btn-primary">
                <span class="icon-refresh"></span> Перезагрузить страницу
            </button>
        </div>
    </div>

    <div id="importError" style="display: none;" class="alert alert-danger">
        <h4>Ошибка импорта!</h4>
        <p id="errorMessage"></p>
        <p>Страница будет перезагружена через 5 секунд...</p>
    </div>

    <!-- Инструкция -->
    <div class="card mt-5">
        <div class="card-header">
            <h3>Требования к файлу</h3>
        </div>
        <div class="card-body">
            <ul>
                <li><strong>Обязательные колонки:</strong> 
                    <ul>
                        <li>Категория (Номер/ID/Название)</li>
                        <li>Производитель</li>
                        <li>Артикул</li>
                        <li>Наименование товара</li>
                        <li>Цена (базовая цена)</li>
                    </ul>
                </li>
                <li><strong>Опциональные колонки:</strong> 
                    <ul>
                        <li>Модификатор цены (новая цена, переопределяет базовую)</li>
                        <li>Количество на складе</li>
                        <li>Изображение</li>
                    </ul>
                </li>
                <li>Цены указываются в числовом формате (например: 1250.50 или 1,250.50)</li>
                <li>Если "Модификатор цены" указан и больше 0, он заменит базовую цену</li>
                <li>Для категорий указывайте ID существующей категории или ее точное название</li>
                <li>Если категория не найдена, товар не будет создан/обновлен</li>
                <li>Первая строка - заголовки колонок</li>
                <li><strong>Кастомные поля:</strong> 
                    <ul>
                        <li>Добавьте колонки с названиями кастомных полей</li>
                        <li>Выберите соответствующий профиль для импорта полей</li>
                        <li>Поля будут автоматически сопоставлены с товарами</li>
                    </ul>
                </li>
                <li>Максимальный размер: <?php echo ini_get('upload_max_filesize'); ?></li>
            </ul>
        </div>
    </div>
</div>

