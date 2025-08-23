// Глобальные переменные
window.importInProgress = false;
window.lastProgressData = null;
window.completionChecks = 0;
window.lastProgressTime = 0;

// Функция проверки прогресса
function checkImportProgress() {
    if (!window.importInProgress) {
        console.log('Import not in progress, stopping checks');
        return;
    }
    
    console.log('Checking import progress...');
    
    $.ajax({
        url: window.location.origin + '/index.php?option=com_estakadaimport&task=import.getProgress&format=json',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            console.log('Progress response:', response);
            
            if (response.success && response.data) {
                window.lastProgressData = response.data;
                
                // Обновляем время последнего прогресса
                window.lastProgressTime = Date.now() / 1000;
                
                updateProgressBar(response.data);
                
                // Проверяем условия завершения
                const isComplete = response.data.current >= response.data.total && response.data.total > 0;
                
                if (isComplete) {
                    window.completionChecks++;
                    console.log('Completion check #' + window.completionChecks);
                    
                    // Требуем 2 последовательных подтверждения завершения
                    if (window.completionChecks >= 2) {
                        console.log('Import confirmed completed');
                        completeImport();
                    } else {
                        setTimeout(checkImportProgress, 1000);
                    }
                } else {
                    window.completionChecks = 0;
                    setTimeout(checkImportProgress, 2000);
                }
            } else {
                setTimeout(checkImportProgress, 2000);
            }
        },
        error: function() {
            setTimeout(checkImportProgress, 2000);
        }
    });
}

// Функция обновления прогрессбара (упрощенная версия)
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
        
        // Упрощенный расчет оставшегося времени
        if (progressData.current > 0 && progressData.total > 0) {
            // Фиксированное предположение: 5 секунд на изображение
            const timePerImage = 5;
            const remainingImages = progressData.total - progressData.current;
            const remainingTime = Math.round(timePerImage * remainingImages);
            
            if (remainingTime > 0) {
                $('#timeRemaining').text(remainingTime + ' сек');
            } else {
                $('#timeRemaining').text('Завершено');
            }
        } else {
            $('#timeRemaining').text('Расчет...');
        }
    }
}

// Функция завершения импорта
function completeImport() {
    console.log('Finalizing import completion...');
    
    window.importInProgress = false;
    
    if (window.fallbackInterval) {
        clearInterval(window.fallbackInterval);
    }
    
    // Даем время увидеть завершенный прогресс
    setTimeout(function() {
        $('#importProgress').hide();
        $('#importComplete').show();
        
        let countdown = 5;
        $('#reloadCountdown').text(countdown);
        
        const countdownInterval = setInterval(function() {
            countdown--;
            $('#reloadCountdown').text(countdown);
            
            if (countdown <= 0) {
                clearInterval(countdownInterval);
                location.reload();
            }
        }, 1000);
    }, 1000);
}

// Функция для обработки ошибок импорта
function handleImportError(errorMessage) {
    console.error('Import error:', errorMessage);
    
    window.importInProgress = false;
    
    if (window.fallbackInterval) {
        clearInterval(window.fallbackInterval);
    }
    
    $('#importProgress').hide();
    $('#importError').show();
    $('#errorMessage').text(errorMessage);
    
    setTimeout(function() {
        location.reload();
    }, 5000);
}

// Автозапуск проверки прогресса если уже идет импорт
jQuery(document).ready(function($) {
    if ($('#importProgress').is(':visible')) {
        window.importInProgress = true;
        setTimeout(checkImportProgress, 1000);
    }
});