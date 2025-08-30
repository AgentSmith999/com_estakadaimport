<?php
namespace Joomla\Component\Estakadaimport\Site\Model\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

trait ProgressTrait
{
    /**
     * Установка прогресса импорта (файловая версия)
     */
    protected function setImportProgress($current, $total, $currentImage = '')
    {
        try {
            $user = Factory::getUser();

            // Рассчитываем скорость обработки
            static $lastUpdateTime = 0;
            static $lastProcessed = 0;
            
            $currentTime = microtime(true);
            $processingSpeed = 0;
            
            if ($lastUpdateTime > 0 && $lastProcessed > 0 && $current > $lastProcessed) {
                $timeDiff = $currentTime - $lastUpdateTime;
                $processedDiff = $current - $lastProcessed;
                $processingSpeed = $timeDiff / $processedDiff;
            }
            
            $lastUpdateTime = $currentTime;
            $lastProcessed = $current;

            $progress = [
                'current' => $current,
                'total' => $total,
                'currentImage' => $currentImage,
                'timestamp' => time(),
                'user_id' => $user->id,
                'percentage' => $total > 0 ? round(($current / $total) * 100) : 0,
                'error' => strpos($currentImage, 'ERROR_') === 0 ? $currentImage : false
            ];
            
            // Создаем временную директорию если не существует
            $tmpDir = JPATH_SITE . '/tmp/estakada_import';
            if (!file_exists($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            
            $cacheFile = $tmpDir . '/progress_' . $user->id . '.json';
            $result = file_put_contents($cacheFile, json_encode($progress));
            
            if ($result === false) {
                error_log('Failed to write progress file: ' . $cacheFile);
            } else {
                error_log('Progress updated: ' . $current . '/' . $total . ' - ' . $currentImage);
            }
            
        } catch (\Exception $e) {
            error_log('Error setting file progress: ' . $e->getMessage());
        }
    }

    /**
     * Получение прогресса импорта (файловая версия)
     */
    public function getImportProgress()
    {
        try {
            $user = Factory::getUser();
            $tmpDir = JPATH_SITE . '/tmp/estakada_import';
            $cacheFile = $tmpDir . '/progress_' . $user->id . '.json';
            
            error_log('Looking for progress file: ' . $cacheFile);
            
            if (file_exists($cacheFile)) {
                $content = file_get_contents($cacheFile);
                $progress = json_decode($content, true);
                
                error_log('Progress file content: ' . $content);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('JSON decode error: ' . json_last_error_msg());
                    return ['completed' => true]; // Возвращаем завершение при ошибке
                }
                
                // Если прогресс устарел (больше 10 минут)
                if (!empty($progress) && (time() - $progress['timestamp']) > 600) {
                    error_log('Progress expired, deleting file');
                    unlink($cacheFile);
                    return ['completed' => true]; // Возвращаем завершение при устаревании
                }
                
                // Добавляем флаг завершения
                $progress['completed'] = false;
                error_log('Returning progress data: ' . json_encode($progress));
                return $progress;
            }
            
            error_log('No progress file found - import completed');
            return ['completed' => true]; // Файл не найден - импорт завершен
            
        } catch (\Exception $e) {
            error_log('Error getting file progress: ' . $e->getMessage());
            return ['completed' => true]; // Возвращаем завершение при ошибке
        }
    }

    /**
     * Очистка прогресса импорта (файловая версия)
     */
    public function clearImportProgress()
    {
        try {
            $user = Factory::getUser();
            $tmpDir = JPATH_SITE . '/tmp/estakada_import';
            $cacheFile = $tmpDir . '/progress_' . $user->id . '.json';
            
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
                error_log('Progress file cleared: ' . $cacheFile);
            }
            
        } catch (\Exception $e) {
            error_log('Error clearing file progress: ' . $e->getMessage());
        }
    }


}