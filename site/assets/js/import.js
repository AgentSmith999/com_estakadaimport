// Глобальные переменные
window.importInProgress = false;
window.lastProgressData = null;
window.completionChecks = 0;
window.totalImages = 0;
window.totalRows = 0;
window.currentProfileId = null;
window.importStarted = false;
window.importSuccessfullyCompleted = false;

// Функция для проверки несоответствия профиля
function checkForProfileMismatch(errorMessage, responseData) {
    // Проверяем стандартные признаки ошибки несоответствия профиля
    const profileMismatchIndicators = [
        'Несоответствие профиля',
        'ERROR_PROFILE_MISMATCH',
        'выбран профиль',
        'специфических полей',
        '❌',
        'profile mismatch',
        'column mismatch',
        'header mismatch',
        'неверный профиль',
        'неправильный профиль',
        'mismatch',
        'несоответствие'
    ];
    
    // Проверяем текст ошибки
    const errorText = errorMessage?.toString().toLowerCase() || '';
    const hasProfileMismatch = profileMismatchIndicators.some(indicator => 
        errorText.includes(indicator.toLowerCase())
    );
    
    if (hasProfileMismatch) {
        return true;
    }
    
    // Дополнительная проверка: если ошибка происходит при смене профиля
    if (window.currentProfileId && responseData) {
        // Проверяем, содержит ли ответ данные о ожидаемых/полученных заголовках
        const responseText = JSON.stringify(responseData).toLowerCase();
        const hasHeaderInfo = responseText.includes('header') || 
                             responseText.includes('column') || 
                             responseText.includes('expected') ||
                             responseText.includes('получен') ||
                             responseText.includes('ожида');
        
        if (hasHeaderInfo) {
            return true;
        }
    }
    
    return false;
}

// Функция для обработки ошибок импорта
function handleImportError(errorMessage, responseData = null) {
    console.log('Handle import error called with:', errorMessage, responseData);
    
    // Если импорт уже успешно завершился по данным прогресса, игнорируем ошибку AJAX
    if (window.importSuccessfullyCompleted) {
        console.log('Import already completed successfully, ignoring AJAX error');
        return;
    }
    
    // Улучшенная проверка ошибки несоответствия профиля
    const isProfileMismatch = checkForProfileMismatch(errorMessage, responseData);
    
    if (isProfileMismatch) {
        showProfileMismatchError(errorMessage);
        return;
    }
    
    console.error('Other import error:', errorMessage);
    
    window.importInProgress = false;
    window.importStarted = false;
    
    if (window.fallbackInterval) {
        clearInterval(window.fallbackInterval);
    }
    
    jQuery('#importProgress').hide();
    jQuery('#importComplete').hide();
    jQuery('#profileMismatchError').hide();
    jQuery('#importError').show();
    jQuery('#errorMessage').text(errorMessage);
    
    setTimeout(function() {
        location.reload();
    }, 5000);
}

// Функция для показа ошибки несоответствия профиля
function showProfileMismatchError(errorMessage) {
    console.log('Showing profile mismatch error');
    
    window.importInProgress = false;
    window.importStarted = false;
    
    if (window.fallbackInterval) {
        clearInterval(window.fallbackInterval);
    }
    
    // Останавливаем все анимации прогресса
    jQuery('.progress-bar').stop().css('width', '100%').addClass('bg-danger').removeClass('progress-bar-animated');
    jQuery('#currentImage').html('<span class="text-danger">Ошибка несоответствия профиля!</span>');
    jQuery('#timeRemaining').text('Прервано');
    
    // Создаем информативное сообщение об ошибке
    const profileName = jQuery('#import_profile option:selected').text();
    const errorHtml = `
        <p><strong>Обнаружено несоответствие профиля!</strong></p>
        <p>Вы выбрали профиль <strong>${profileName}</strong>, 
        но файл содержит данные для другого профиля товаров.</p>
        <p>Пожалуйста:</p>
        <ul>
            <li>Проверьте, что выбрали правильный профиль в настройках импорта</li>
            <li>Убедитесь, что Excel файл соответствует выбранному профилю товаров</li>
            <li>Скачайте шаблон Excel для выбранного профиля</li>
            <li>Или выберите соответствующий профиль для вашего файла</li>
        </ul>
        <p class="text-muted"><small>Детали ошибки: ${errorMessage || 'Несоответствие структуры файла'}</small></p>
    `;
    
    // Обновляем сообщение об ошибке
    jQuery('#profileMismatchError').html(`
        <h4>❌ Несоответствие профиля!</h4>
        ${errorHtml}
        <div class="mt-3">
            <button id="reloadPageProfile" class="btn btn-primary">
                <span class="icon-refresh"></span> Перезагрузить страницу
            </button>
        </div>
    `);
    
    // Показываем блок ошибки профиля
    jQuery('#importProgress').hide();
    jQuery('#importComplete').hide();
    jQuery('#importError').hide();
    jQuery('#profileMismatchError').show();
    
    // Обработчик кнопки перезагрузки
    jQuery('#reloadPageProfile').off('click').on('click', function() {
        location.reload();
    });
}

// Функция для анализа файла
function analyzeFile(callback) {
    const formData = new FormData();
    formData.append('xlsfile', jQuery('#xlsfile')[0].files[0]);
    formData.append('import_profile', jQuery('#import_profile').val());
    formData.append('task', 'import.analyzeSimple');
    formData.append('format', 'json');
    formData.append(Joomla.getOptions('csrf.token'), 1);

    jQuery.ajax({
        url: window.location.origin + '/index.php?option=com_estakadaimport',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            console.log('Analyze response:', response);
            
            if (response.success && response.data) {
                callback(response.data.totalImages, response.data.totalRows);
            } else {
                // Проверяем, не является ли это ошибкой несоответствия профиля
                if (response.message && checkForProfileMismatch(response.message, response)) {
                    handleImportError(response.message, response);
                } else {
                    console.error('Analyze error:', response.message);
                    handleImportError('Ошибка анализа файла: ' + (response.message || 'Неизвестная ошибка'), response);
                }
                window.importInProgress = false;
                jQuery('#importProgress').hide();
            }
        },
        error: function(xhr, status, error) {
            console.error('Analyze AJAX error:', status, error);
            handleImportError('Ошибка при анализе файла: ' + error);
            window.importInProgress = false;
            jQuery('#importProgress').hide();
        }
    });
}

// Запуск импорта
function startFullImport() {
    window.importStarted = true;
    window.importSuccessfullyCompleted = false;
    
    const formData = new FormData();
    formData.append('xlsfile', jQuery('#xlsfile')[0].files[0]);
    formData.append('import_profile', jQuery('#import_profile').val());
    formData.append('task', 'import.fullProcess');
    formData.append('format', 'json');
    formData.append(Joomla.getOptions('csrf.token'), 1);

    // Сбрасываем счетчики завершения
    window.completionChecks = 0;
    
    // Запускаем fallback анимацию
    startFallbackAnimation(window.totalImages);
    
    // Запускаем проверку реального прогресса
    setTimeout(checkImportProgress, 1000);
    
    jQuery.ajax({
        url: window.location.origin + '/index.php?option=com_estakadaimport',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(response) {
            console.log('Import AJAX completed:', response);
            
            // Игнорируем ошибку AJAX, если импорт уже завершился успешно
            if (response.success === false && !window.importSuccessfullyCompleted) {
                handleImportError(response.message || 'Ошибка импорта', response);
            }
            else if (response.success === true) {
                console.log('Import started successfully, waiting for progress completion');
            }
        },
        error: function(xhr, status, error) {
            console.error('Import AJAX error:', status, error);
            
            if (!window.importSuccessfullyCompleted) {
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    handleImportError(errorResponse?.message || 'Ошибка при импорте: ' + error, errorResponse);
                } catch (e) {
                    handleImportError('Ошибка при импорте: ' + error);
                }
            } else {
                console.log('AJAX error occurred but import was already completed successfully');
            }
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
                jQuery('.progress-bar').css('width', percentage + '%');
                jQuery('#processedCount').text(current);
                
                if (current % 5 === 0) {
                    jQuery('#currentImage').html('<span class="text-success">Обработка... ' + current + '/' + totalImages + '</span>');
                }
            }
        } else if (!window.importInProgress) {
            clearInterval(window.fallbackInterval);
        }
    }, 100);
}

// Инициализация после загрузки DOM
document.addEventListener('DOMContentLoaded', function() {
    // Обработка отправки формы
    jQuery('#adminForm').on('submit', function(e) {
        if (window.importInProgress) {
            e.preventDefault();
            return false;
        }
        
        const fileInput = jQuery('#xlsfile')[0];
        if (fileInput.files.length === 0) {
            alert('Пожалуйста, выберите файл для импорта');
            e.preventDefault();
            return false;
        }
        
        e.preventDefault();
        
        window.importInProgress = true;
        window.currentProfileId = jQuery('#import_profile').val();
        jQuery('#importProgress').show();
        jQuery('#importComplete').hide();
        jQuery('#importError').hide();
        jQuery('#profileMismatchError').hide();
        jQuery('.progress-bar').css('width', '0%').removeClass('bg-danger').addClass('progress-bar-animated');
        
        // Сбрасываем счетчики
        jQuery('#processedCount').text('0');
        jQuery('#currentImage').text('Подготовка к импорту...');
        
        // Анализируем файл для получения реальных данных
        analyzeFile(function(images, rows) {
            window.totalImages = images;
            window.totalRows = rows;
            
            jQuery('#totalCount').text(images);
            jQuery('#rowCount').text(rows);
            jQuery('#totalCount2').text(images);
            
            // Запускаем импорт
            startFullImport();
        });
    });
    
    // Обработчик кнопки отмены
    jQuery('#cancelImport').on('click', function() {
        if (confirm('Прервать импорт?')) {
            window.importInProgress = false;
            if (window.fallbackInterval) {
                clearInterval(window.fallbackInterval);
            }
            location.reload();
        }
    });
});