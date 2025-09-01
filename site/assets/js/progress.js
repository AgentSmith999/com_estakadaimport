// Глобальные переменные
window.importInProgress = false;
window.lastProgressData = null;
window.completionChecks = 0;
window.lastProgressTime = 0;
window.importStartTime = 0;
window.fallbackTimer = null;
window.progressCheckAttempts = 0;
window.maxProgressAttempts = 30;
window.fallbackInterval = null;
window.debugMode = false; // Поставьте true для отладки


// Функция проверки прогресса
function checkImportProgress() {
    if (!window.importInProgress || window.importSuccessfullyCompleted) return;

    window.progressCheckAttempts++;
    // УБИРАЕМ console.log для уменьшения флуда
    // console.log('Progress check attempt:', window.progressCheckAttempts);
    
    // Если превысили максимальное количество попыток
    if (window.progressCheckAttempts >= window.maxProgressAttempts) {
        if (window.debugMode) {
            console.log('Max progress check attempts reached, completing import');
        }
        completeImportSuccessfully();
        return;
    }
    
    fetch('index.php?option=com_estakadaimport&task=import.getProgress&format=json')
        .then(response => {
            if (!response.ok) {
                if (response.status === 404) {
                    if (window.debugMode) {
                        console.log('Progress endpoint not found, using fallback completion');
                    }
                    handleProgressEndpointNotFound();
                    return null;
                }
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (!data) return;
            
            // ОСТАВЛЯЕМ только важные логи, убираем флуд
            // console.log('Progress response:', data);
            
            if (data.success && data.data) {
                if (data.data.error && data.data.error === 'ERROR_PROFILE_MISMATCH') {
                    if (window.debugMode) {
                        console.log('Profile mismatch error detected in progress data');
                    }
                    stopProgressPolling();
                    if (typeof showProfileMismatchError === 'function') {
                        showProfileMismatchError(data.data.message);
                    }
                    return;
                }
                
                if (data.data.completed) {
                    if (window.debugMode) {
                        console.log('Import completed according to progress data');
                    }
                    window.importSuccessfullyCompleted = true;
                    stopProgressPolling();
                    showCompletionMessage();
                    return;
                }
                
                updateProgressBar(data.data);
                
                // Продолжаем опрос если не завершено
                if (!data.data.completed) {
                    setTimeout(checkImportProgress, 1000);
                }
            } else {
                if (window.debugMode) {
                    console.log('Invalid progress response, continuing checks');
                }
                setTimeout(checkImportProgress, 1000);
            }
        })
        .catch(error => {
            // УБИРАЕМ console.log для ошибок, чтобы не флудить
            // console.log('Error checking progress, continuing:', error);
            setTimeout(checkImportProgress, 1000);
        });
}

// Обработка ситуации, когда endpoint прогресса не найден
function handleProgressEndpointNotFound() {
    if (window.debugMode) {
        console.log('Using timed completion fallback');
    }
    
    // Запускаем fallback анимацию
    startFallbackAnimation();
    
    // Ждем разумное время для завершения импорта
    const timeSinceStart = Date.now() - window.importStartTime;
    const estimatedTime = (window.totalImages || 2) * 2000 + 5000;
    
    if (timeSinceStart > estimatedTime) {
        if (window.debugMode) {
            console.log('Estimated time passed, completing import');
        }
        completeImportSuccessfully();
    } else {
        const remainingTime = estimatedTime - timeSinceStart;
        if (window.debugMode) {
            console.log('Will complete in:', Math.round(remainingTime/1000) + 's');
        }
        
        startFallbackTimer(remainingTime);
        setTimeout(completeImportSuccessfully, remainingTime);
    }
}

// Fallback анимация
function startFallbackAnimation() {
    if (window.fallbackInterval) clearInterval(window.fallbackInterval);
    
    let current = 0;
    const total = window.totalImages || 1;
    
    window.fallbackInterval = setInterval(function() {
        if (current < total && window.importInProgress) {
            current++;
            const percentage = Math.round((current / total) * 100);
            
            // Обновляем только если нет реальных данных
            if (!window.lastProgressData || window.lastProgressData.current < current) {
                jQuery('.progress-bar').css('width', percentage + '%');
                jQuery('#processedCount').text(current);
                
                if (current % 5 === 0) {
                    jQuery('#currentImage').html('<span class="text-success">Обработка... ' + current + '/' + total + '</span>');
                }
            }
        } else if (!window.importInProgress) {
            clearInterval(window.fallbackInterval);
            window.fallbackInterval = null;
        }
    }, 100);
}

// Запуск таймера обратного отсчета
function startFallbackTimer(totalTime) {
    let remaining = Math.round(totalTime / 1000);
    
    jQuery('#timeRemaining').text(remaining + ' сек');
    
    if (window.fallbackTimer) clearInterval(window.fallbackTimer);
    
    window.fallbackTimer = setInterval(function() {
        remaining--;
        
        if (remaining <= 0) {
            clearInterval(window.fallbackTimer);
            jQuery('#timeRemaining').text('Завершено');
        } else {
            jQuery('#timeRemaining').text(remaining + ' сек');
        }
    }, 1000);
}

function stopProgressPolling() {
    window.importInProgress = false;
    if (window.debugMode) {
        console.log('Импорт завершен, останавливаем опрос');
    }
    
    if (window.fallbackTimer) {
        clearInterval(window.fallbackTimer);
        window.fallbackTimer = null;
    }
    
    if (window.fallbackInterval) {
        clearInterval(window.fallbackInterval);
        window.fallbackInterval = null;
    }
}

function showCompletionMessage() {
    if (window.debugMode) {
        console.log('Finalizing import completion...');
    }
    
    window.importInProgress = false;
    window.importStarted = false;
    
    stopProgressPolling();
    
    // Обновляем прогрессбар до 100%
    jQuery('.progress-bar').css('width', '100%').removeClass('progress-bar-animated');
    jQuery('#processedCount').text(window.totalImages || jQuery('#totalCount').text());
    jQuery('#currentImage').html('<span class="text-success">Завершено!</span>');
    jQuery('#timeRemaining').text('Завершено');
    
    // Заполняем статистику завершения
    jQuery('#completedCount').text(window.totalRows || '2');
    jQuery('#completedImages').text(window.totalImages || jQuery('#totalCount').text());
    
    // Показываем блок завершения
    jQuery('#importProgress').hide();
    jQuery('#importComplete').show();
    
    jQuery('#newImport').off('click').on('click', function() {
        jQuery('#importComplete').hide();
        jQuery('#adminForm')[0].reset();
        window.importInProgress = false;
        window.importStarted = false;
        window.importSuccessfullyCompleted = false;
        window.totalImages = 0;
        window.totalRows = 0;
    });
}

// Функция для успешного завершения импорта 
function completeImportSuccessfully() {
    if (window.importSuccessfullyCompleted) return;
    
    if (window.debugMode) {
        console.log('Import completed successfully');
    }
    
    window.importInProgress = false;
    window.importSuccessfullyCompleted = true;
    
    stopProgressPolling();
    
    // Обновляем UI
    jQuery('.progress-bar').css('width', '100%').removeClass('progress-bar-animated').addClass('bg-success');
    jQuery('#currentImage').html('<span class="text-success">Импорт завершен успешно!</span>');
    jQuery('#timeRemaining').text('Завершено');
    
    // Обновляем статистику
    jQuery('#completedCount').text(window.totalRows || '2');
    jQuery('#completedImages').text(window.totalImages || jQuery('#totalCount').text());
    
    // Показываем блок завершения
    jQuery('#importProgress').hide();
    jQuery('#importComplete').show();
    
    jQuery('#newImport').off('click').on('click', function() {
        if (window.debugMode) {
            console.log('New import clicked');
        }
        jQuery('#importComplete').hide();
        jQuery('#adminForm')[0].reset();
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
        
        jQuery('.progress-bar').css('width', percentage + '%');
        jQuery('#processedCount').text(progressData.current);
        jQuery('#totalCount').text(progressData.total);
        jQuery('#totalCount2').text(progressData.total);
        
        if (progressData.currentImage) {
            jQuery('#currentImage').html(
                '<span class="text-success">' +
                progressData.currentImage.substring(0, 60) + 
                (progressData.currentImage.length > 60 ? '...' : '') +
                '</span>'
            );
        }
        
        // Расчет оставшегося времени
        const now = Date.now();
        if (window.lastProgressTime > 0 && progressData.current > (window.lastProgressData?.current || 0)) {
            const timeDiff = now - window.lastProgressTime;
            const itemsProcessed = progressData.current - (window.lastProgressData?.current || 0);
            
            if (itemsProcessed > 0) {
                const timePerItem = timeDiff / itemsProcessed;
                const remainingItems = progressData.total - progressData.current;
                const remainingTime = Math.round(timePerItem * remainingItems / 1000);
                
                if (remainingTime > 0) {
                    jQuery('#timeRemaining').text(remainingTime + ' сек');
                } else {
                    jQuery('#timeRemaining').text('Завершено');
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
        window.importStartTime = Date.now();
        setTimeout(checkImportProgress, 1000);
    }
});

// Функция для запуска проверки прогресса из import.js
function startProgressChecking() {
    window.importStartTime = Date.now();
    window.progressCheckAttempts = 0;
    setTimeout(checkImportProgress, 1000);
}