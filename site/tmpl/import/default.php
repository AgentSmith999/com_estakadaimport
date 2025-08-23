<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;

HTMLHelper::_('formbehavior.chosen', 'select');

$app = Factory::getApplication();
?>

<div class="com-estakadaimport-import">
    <h2 class="mb-4">Импорт товаров из Excel</h2>

    <form action="<?php echo Route::_('index.php?option=com_estakadaimport&task=import.upload'); ?>" 
          method="post" name="adminForm" id="adminForm" class="form-horizontal" enctype="multipart/form-data">

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
        <h4>Импорт завершен успешно!</h4>
        <p>Страница будет перезагружена через <span id="reloadCountdown">5</span> секунд...</p>
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
                        <li>Артикул</li>
                        <li>Наименование товара</li>
                        <li>Категория (Номер/ID/Название)</li>
                    </ul>
                </li>
                <li><strong>Опциональные колонки:</strong> 
                    <ul>
                        <li>Количество на складе</li>
                        <li>Изображение</li>
                    </ul>
                </li>
                <li>Для категорий указывайте ID существующей категории или ее точное название</li>
                <li>Если категория не найдена, товар не будет создан/обновлен</li>
                <li>Первая строка - заголовки колонок</li>
                <li>Максимальный размер: <?php echo ini_get('upload_max_filesize'); ?></li>
            </ul>
        </div>
    </div>
</div>

<script src="<?php echo JUri::root(); ?>components/com_estakadaimport/tmpl/import/progress.js"></script>

<script>
jQuery(document).ready(function($) {
    // Глобальные переменные
    window.importInProgress = false;
    window.lastProgressData = null;
    window.completionChecks = 0;
    window.totalImages = 0;
    window.totalRows = 0;
        
    // Обработка отправки формы
    $('#adminForm').on('submit', function(e) {
        if (window.importInProgress) {
            e.preventDefault();
            return false;
        }
        
        const fileInput = $('#xlsfile')[0];
        if (fileInput.files.length === 0) {
            alert('Пожалуйста, выберите файл для импорта');
            e.preventDefault();
            return false;
        }
        
        e.preventDefault();
        
        window.importInProgress = true;
        $('#importProgress').show();
        $('#importComplete').hide();
        $('#importError').hide();
        $('.progress-bar').css('width', '0%');
        
        // Сбрасываем счетчики
        $('#processedCount').text('0');
        $('#currentImage').text('Подготовка к импорту...');
        
        // Анализируем файл для получения реальных данных
        analyzeFile(function(images, rows) {
            window.totalImages = images;
            window.totalRows = rows;
            
            $('#totalCount').text(images);
            $('#rowCount').text(rows);
            $('#totalCount2').text(images);
            
            // Запускаем импорт
            startFullImport();
        });
    });
    
    // Функция для анализа файла
    function analyzeFile(callback) {
        const formData = new FormData();
        formData.append('xlsfile', $('#xlsfile')[0].files[0]);
        formData.append('task', 'import.analyzeSimple');
        formData.append('format', 'json');
        formData.append(Joomla.getOptions('csrf.token'), 1);

        $.ajax({
            url: window.location.origin + '/index.php?option=com_estakadaimport',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    callback(response.data.totalImages, response.data.totalRows);
                } else {
                    // Используем приблизительные значения
                    const totalRows = 50;
                    const totalImages = Math.round(totalRows * 2.4);
                    callback(totalImages, totalRows);
                }
            },
            error: function() {
                // Используем приблизительные значения при ошибке
                const totalRows = 50;
                const totalImages = Math.round(totalRows * 2.4);
                callback(totalImages, totalRows);
            }
        });
    }
    
    // Запуск импорта
    function startFullImport() {
        const formData = new FormData($('#adminForm')[0]);
        formData.append('task', 'import.fullProcess');
        formData.append('format', 'json');
        formData.append(Joomla.getOptions('csrf.token'), 1);

        // Сбрасываем счетчики завершения
        window.completionChecks = 0;
        
        // Запускаем fallback анимацию
        startFallbackAnimation(window.totalImages);
        
        // Запускаем проверку реального прогресса
        setTimeout(checkImportProgress, 1000);
        
        $.ajax({
            url: window.location.origin + '/index.php?option=com_estakadaimport',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                console.log('Import AJAX completed:', response);
            },
            error: function(xhr, status, error) {
                console.error('Import AJAX error:', status, error);
                handleImportError('Ошибка при импорте: ' + error);
            }
        });
    }

    // Fallback анимация
    function startFallbackAnimation(totalImages) {
        let current = 0;
        window.fallbackInterval = setInterval(function() {
            if (current < totalImages && window.importInProgress) {
                current++;
                const percentage = Math.round((current / totalImages) * 100);
                
                // Обновляем только если нет реальных данных
                if (!window.lastProgressData || window.lastProgressData.current < current) {
                    $('.progress-bar').css('width', percentage + '%');
                    $('#processedCount').text(current);
                    
                    if (current % 5 === 0) {
                        $('#currentImage').html('<span class="text-success">Обработка... ' + current + '/' + totalImages + '</span>');
                    }
                }
            } else if (!window.importInProgress) {
                clearInterval(window.fallbackInterval);
            }
        }, 100);
    }
    
    $('#cancelImport').on('click', function() {
        if (confirm('Прервать импорт?')) {
            window.importInProgress = false;
            if (window.fallbackInterval) {
                clearInterval(window.fallbackInterval);
            }
            location.reload();
        }
    });
});
</script>

<style>
.progress { 
    height: 25px; 
    margin-bottom: 15px;
}
.import-status { 
    font-size: 14px; 
    background: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 15px;
}
.import-status p { 
    margin-bottom: 5px; 
}
.import-status strong {
    color: #495057;
}
#currentImage {
    font-family: monospace;
    background: #fff;
    padding: 5px;
    border-radius: 3px;
    border: 1px solid #dee2e6;
    word-break: break-all;
}
.text-success { color: #28a745 !important; }
.text-danger { color: #dc3545 !important; }
.btn-cancel {
    margin-top: 15px;
}
.import-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
}
.stat-item {
    background: #e9ecef;
    padding: 10px;
    border-radius: 5px;
    min-width: 120px;
}
.stat-value {
    font-size: 18px;
    font-weight: bold;
    color: #007bff;
}
</style>