<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;

HTMLHelper::_('formbehavior.chosen', 'select');

$app = Factory::getApplication();
$categories = $this->get('Categories'); // Получаем категории из модели
?>

<div class="com-estakadaimport-import">
    <h2 class="mb-4">Импорт товаров из Excel</h2>

    <?php foreach ($app->getMessageQueue() as $msg): ?>
        <div class="alert alert-<?php echo $msg['type']; ?>">
            <?php echo $msg['message']; ?>
        </div>
    <?php endforeach; ?>

    <form action="<?php echo Route::_('index.php?option=com_estakadaimport&task=import.upload'); ?>" 
          method="post" name="adminForm" id="adminForm" class="form-horizontal" enctype="multipart/form-data">

        <!-- Выбор категории -->
        <div class="control-group">
            <label class="control-label" for="category_id">Категория:</label>
            <div class="controls">
                <select name="category_id" id="category_id" class="form-control" required>
                    <option value="">-- Выберите категорию --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category->value; ?>">
                            <?php echo str_repeat('- ', $category->level) . $category->text; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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

    <!-- Инструкция -->
    <div class="card mt-5">
        <div class="card-header">
            <h3>Требования к файлу</h3>
        </div>
        <div class="card-body">
            <ul>
                <li>Обязательные колонки: <strong>Артикул</strong>, <strong>Наименование товара</strong></li>
                <li>Опциональные колонки: <strong>Количество на складе</strong></li>
                <li>Первая строка - заголовки колонок</li>
                <li>Максимальный размер: <?php echo ini_get('upload_max_filesize'); ?></li>
            </ul>
        </div>
    </div>
</div>