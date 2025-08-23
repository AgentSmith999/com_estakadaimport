<?php
namespace Joomla\Component\Estakadaimport\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Session\Session;

class ImportController extends BaseController
{
    /**
     * Загрузка и обработка Excel файла
     */
    public function upload()
    {
        // Проверка токена
        $this->checkToken();

        $app = Factory::getApplication();
        $input = $app->input;

        // Сохраняем текущий URL для редиректа
        $returnUrl = $input->server->getString('HTTP_REFERER', Route::_('index.php?option=com_estakadaimport&view=import', false));

        try {
            // Получаем загруженный файл
            $file = $input->files->get('xlsfile');

            // Проверяем, что файл был загружен
            if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception("Файл не был загружен");
            }

            // Проверяем расширение файла
            $extension = strtolower(File::getExt($file['name']));
            if (!in_array($extension, ['xls', 'xlsx'])) {
                throw new \Exception("Неверное расширение файла. Разрешены только .xls и .xlsx");
            }

            // Проверяем размер файла
            $maxSize = $this->getMaxUploadSize();
            if ($file['size'] > $maxSize) {
                throw new \Exception("Файл слишком большой. Максимальный размер: " . $this->formatSize($maxSize));
            }

            // Создаем временную директорию, если не существует
            $tmpPath = JPATH_ROOT . '/tmp/com_estakadaimport';
            if (!is_dir($tmpPath)) {
                if (!mkdir($tmpPath, 0755, true)) {
                    throw new \Exception("Не удалось создать временную директорию");
                }
            }

            // Генерируем уникальное имя файла
            $filename = uniqid('import_') . '.' . $extension;
            $filePath = $tmpPath . '/' . $filename;

            // Перемещаем загруженный файл
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new \Exception("Не удалось сохранить загруженный файл");
            }

            // Обрабатываем файл через модель
            $model = $this->getModel('Import');
            $result = $model->importFromExcel($filePath);

            // Удаляем временный файл
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            if ($result) {
              //  $app->enqueueMessage("Импорт успешно завершен", 'success');
            } else {
                $app->enqueueMessage("Импорт завершен с ошибками", 'warning');
            }

        } catch (\Exception $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        // Перенаправляем обратно на ту же страницу, откуда пришли
        $this->setRedirect($returnUrl);
    }

    /**
     * Получение максимального размера загружаемого файла
     */
    protected function getMaxUploadSize()
    {
        $maxSize = ini_get('upload_max_filesize');
        $maxSize = $this->convertToBytes($maxSize);
        
        $postMaxSize = ini_get('post_max_size');
        $postMaxSize = $this->convertToBytes($postMaxSize);
        
        return min($maxSize, $postMaxSize);
    }

    /**
     * Конвертация размера в байты
     */
    protected function convertToBytes($size)
    {
        $unit = strtoupper(substr($size, -1));
        $value = (int)substr($size, 0, -1);
        
        switch ($unit) {
            case 'G': $value *= 1024;
            case 'M': $value *= 1024;
            case 'K': $value *= 1024;
        }
        
        return $value;
    }

    /**
     * Форматирование размера файла
     */
    protected function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Проверка CSRF токена
     */
    public function checkToken($method = 'post', $redirect = true)
    {
        if (!Factory::getSession()->checkToken($method)) {
            if ($redirect) {
                // Используем referer для редиректа назад
                $returnUrl = Factory::getApplication()->input->server->getString('HTTP_REFERER', Route::_('index.php?option=com_estakadaimport&view=import', false));
                $this->setRedirect($returnUrl);
                $this->redirect();
            }
            throw new \Exception("Неверный токен безопасности");
        }
    }

    /**
     * Анализ Excel файла
     */
    public function analyzeSimple()
    {
        $app = Factory::getApplication();
        
        try {
            // Проверяем токен
            if (!Session::checkToken()) {
                throw new \Exception('Неверный токен');
            }
            
            $input = $app->input;
            $file = $input->files->get('xlsfile');
            
            if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception('Файл не загружен');
            }
            
            // Перемещаем файл во временную директорию
            $tmpDir = JPATH_SITE . '/tmp';
            $tmpFilePath = $tmpDir . '/' . uniqid() . '_' . $file['name'];
            
            if (!move_uploaded_file($file['tmp_name'], $tmpFilePath)) {
                throw new \Exception('Не удалось сохранить временный файл');
            }
            
            // Анализируем файл
            $model = $this->getModel('Import');
            
            if (!method_exists($model, 'analyzeExcelFile')) {
                throw new \Exception('Метод analyzeExcelFile не найден в модели');
            }
            
            $analysis = $model->analyzeExcelFile($tmpFilePath);
            
            // Удаляем временный файл
            if (file_exists($tmpFilePath)) {
                unlink($tmpFilePath);
            }
            
            // Устанавливаем правильный Content-Type для JSON
            $app->setHeader('Content-Type', 'application/json', true);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'totalImages' => $analysis['totalImages'],
                    'totalRows' => $analysis['totalRows'],
                    'fileName' => $file['name'],
                    'fileSize' => $file['size']
                ]
            ]);
            
        } catch (\Exception $e) {
            // Устанавливаем правильный Content-Type для JSON
            $app->setHeader('Content-Type', 'application/json', true);
            
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
        
        $app->close();
    }

    /**
     * Полный процесс импорта
     */
    public function fullProcess()
    {
        $app = Factory::getApplication();
        error_log('EstakadaImport: fullProcess started'); // Добавьте эту строку
        
        try {
            // Проверяем токен
            if (!Session::checkToken()) {
                throw new \Exception('Неверный токен');
            }
            
            $input = $app->input;
            $file = $input->files->get('xlsfile');
            
            if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception('Файл не загружен');
            }
            
            // Перемещаем файл во временную директорию
            $tmpDir = JPATH_SITE . '/tmp';
            $tmpFilePath = $tmpDir . '/' . uniqid() . '_' . $file['name'];
            
            if (!move_uploaded_file($file['tmp_name'], $tmpFilePath)) {
                throw new \Exception('Не удалось сохранить временный файл');
            }
            
            // Выполняем импорт
            $model = $this->getModel('Import');
            $result = $model->importFromExcel($tmpFilePath);
            
            // Удаляем временный файл
            if (file_exists($tmpFilePath)) {
                unlink($tmpFilePath);
            }
            
            // Устанавливаем правильный Content-Type для JSON
            $app->setHeader('Content-Type', 'application/json', true);
            
            echo json_encode([
                'success' => $result,
                'message' => $result ? 'Импорт завершен успешно' : 'Ошибка импорта'
            ]);
            
        } catch (\Exception $e) {
            // Устанавливаем правильный Content-Type для JSON
            $app->setHeader('Content-Type', 'application/json', true);
            
            echo json_encode([
                'success' => false,
                'message' => 'Ошибка: ' . $e->getMessage()
            ]);
        }
        
        $app->close();
    }

    /**
     * Упрощенный импорт для тестирования
     */
    protected function simpleImport($filePath, $model)
    {
        try {
            // Простая имитация импорта
            sleep(3); // Имитация обработки
            
            // Можно также вызвать настоящий метод, но без прогресса
            // return $model->importFromExcel($filePath);
            
            return true;
            
        } catch (\Exception $e) {
            error_log('Simple import error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Получение прогресса импорта
     */
    public function getProgress()
    {
        $app = Factory::getApplication();
        $app->setHeader('Content-Type', 'application/json', true);
        
        try {
            $model = $this->getModel('Import');
            
            if (!method_exists($model, 'getImportProgress')) {
                throw new \Exception('Method getImportProgress not found');
            }
            
            $progress = $model->getImportProgress();
            
            // Добавим отладочную информацию
            $debugInfo = [
                'progress_data' => $progress,
                'total_images_calculated' => $progress['total'] ?? 0,
                'current_processed' => $progress['current'] ?? 0,
                'is_complete' => isset($progress['current'], $progress['total']) && 
                                $progress['current'] >= $progress['total'],
                'timestamp' => time()
            ];
            
            error_log('Progress debug: ' . json_encode($debugInfo));
            
            echo json_encode([
                'success' => true,
                'data' => $progress ?: ['current' => 0, 'total' => 0, 'currentImage' => ''],
                'debug' => $debugInfo
            ]);
            
        } catch (\Exception $e) {
            error_log('Error in getProgress: ' . $e->getMessage());
            
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => ['current' => 0, 'total' => 0, 'currentImage' => '']
            ]);
        }
        
        $app->close();
    }

    /**
     * Очистка старых progress файлов
     */
    protected function cleanupOldProgressFiles()
    {
        try {
            $tmpDir = JPATH_SITE . '/tmp/estakada_import';
            if (file_exists($tmpDir)) {
                $files = glob($tmpDir . '/progress_*.json');
                foreach ($files as $file) {
                    if (filemtime($file) < time() - 3600) { // older than 1 hour
                        unlink($file);
                        error_log('Cleaned up old progress file: ' . $file);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('Error cleaning up progress files: ' . $e->getMessage());
        }
    }

    /**
     * Отмена импорта
     */
    public function cancel()
    {
        try {
            $model = $this->getModel('Import');
            $model->clearImportProgress();
            
            echo json_encode(['success' => true, 'message' => 'Импорт отменен']);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        
        $app = Factory::getApplication();
        $app->close();
    }
}