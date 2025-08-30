// Глобальные переменные
window.importInProgress = false;
window.lastProgressData = null;
window.completionChecks = 0;
window.lastProgressTime = 0;

// Функция проверки прогресса
function checkImportProgress() {
    if (!window.importInProgress) return;
    
    fetch('index.php?option=com_estakadaimport&task=import.getProgress&format=json')
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('Progress response:', data);
            
            if (data.success && data.data) {
                // Проверяем ошибку несоответствия профиля
                if (data.data.error && data.data.error === 'ERROR_PROFILE_MISMATCH') {
                    console.log('Profile mismatch error detected in progress data');
                    stopProgressPolling();
                    showProfileMismatchError(data.data.message);
                    return;
                }
                
                // Если импорт завершен по данным прогресса
                if (data.data.completed) {
                    console.log('Import completed according to progress data');
                    
                    // Устанавливаем флаг успешного завершения
                    window.importSuccessfullyCompleted = true;
                    
                    stopProgressPolling();
                    showCompletionMessage();
                    return;
                }
                
                updateProgressBar(data.data);
                
                // Продолжаем опрос если не завершено
                if (!data.data.completed) {
                    setTimeout(checkImportProgress, 2000);
                }
            } else {
                console.error('Invalid progress response:', data);
                setTimeout(checkImportProgress, 5000);
            }
        })
        .catch(error => {
            console.error('Error checking progress:', error);
            setTimeout(checkImportProgress, 5000);
        });
}

function stopProgressPolling() {
    window.importInProgress = false;
    console.log('Импорт завершен, останавливаем опрос');
}

function showCompletionMessage() {
    console.log('Finalizing import completion...');
    
    window.importInProgress = false;
    window.importStarted = false;
    
    if (window.fallbackInterval) {
        clearInterval(window.fallbackInterval);
    }
    
    // Обновляем прогрессбар до 100%
    $('.progress-bar').css('width', '100%').removeClass('progress-bar-animated');
    $('#processedCount').text(window.totalImages || $('#totalCount').text());
    $('#currentImage').html('<span class="text-success">Завершено!</span>');
    $('#timeRemaining').text('Завершено');
    
    // Заполняем статистику завершения
    $('#completedCount').text(window.totalRows || '?');
    $('#completedImages').text(window.totalImages || $('#totalCount').text());
    
    // Показываем блок завершения
    $('#importProgress').hide();
    $('#importComplete').show();
    
    // Обработчики кнопок
    $('#reloadPage').off('click').on('click', function() {
        location.reload();
    });
    
    $('#newImport').off('click').on('click', function() {
        // Сбрасываем форму для нового импорта
        $('#importComplete').hide();
        $('#adminForm')[0].reset();
        window.importInProgress = false;
        window.importStarted = false;
        window.importSuccessfullyCompleted = false;
        window.totalImages = 0;
        window.totalRows = 0;
    });
}

// Функция обновления прогрессбара
function updateProgressBar(progressData) {
    if (progressData.current !== undefined && progressData.total !== undefined) {
        const percentage = Math.round((progressData.current / progressData.total) * 100);
        
        $('.progress-bar').css('width', percentage + '%');
        $('#processedCount').text(progressData.current);
        $('#totalCount').text(progressData.total);
        $('#totalCount2').text(progressData.total);
        
        if (progressData.currentImage) {
            $('#currentImage').html(
                '<span class="text-success">' +
                progressData.currentImage.substring(0, 60) + 
                (progressData.currentImage.length > 60 ? '...' : '') +
                '</span>'
            );
        }
        
        // Расчет оставшегося времени на основе реальной скорости
        const now = Date.now();
        if (window.lastProgressTime > 0 && progressData.current > (window.lastProgressData?.current || 0)) {
            const timeDiff = now - window.lastProgressTime;
            const itemsProcessed = progressData.current - (window.lastProgressData?.current || 0);
            
            if (itemsProcessed > 0) {
                const timePerItem = timeDiff / itemsProcessed;
                const remainingItems = progressData.total - progressData.current;
                const remainingTime = Math.round(timePerItem * remainingItems / 1000);
                
                if (remainingTime > 0) {
                    $('#timeRemaining').text(remainingTime + ' сек');
                } else {
                    $('#timeRemaining').text('Завершено');
                }
            }
        }
        
        window.lastProgressData = progressData;
        window.lastProgressTime = now;
    }
}

// Автозапуск проверки прогресса если уже идет импорт
jQuery(document).ready(function($) {
    if ($('#importProgress').is(':visible')) {
        window.importInProgress = true;
        window.importStarted = true;
        setTimeout(checkImportProgress, 1000);
    }
});