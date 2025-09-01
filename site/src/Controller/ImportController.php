<?php
namespace Joomla\Component\Estakadaimport\Site\Controller;

defined('_JEXEC') or die;

error_reporting(E_ALL);
ini_set('display_errors', 1);

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Response\JsonResponse;

class ImportController extends BaseController
{
    public function upload()
    {
        // Проверяем CSRF токен
        $this->checkToken();

        $app = Factory::getApplication();
        $user = Factory::getUser();

        // Получаем параметры из запроса
        $profileId = $this->input->getInt('import_profile', 0);
        $selectedVendorId = $this->input->getInt('selected_vendor', null);
        $updatePricesOnly = $this->input->getBool('update_prices_only', false); // НОВЫЙ ПАРАМЕТР
        
        // Проверяем безопасность: только SuperUser может выбирать vendor
        if ($selectedVendorId && !$user->authorise('core.admin')) {
            $app->enqueueMessage('Доступ запрещен', 'error');
            $app->redirect(Route::_('index.php?option=com_estakadaimport', false));
            return false;
        }

        // Обработка загрузки файла
        $file = $this->input->files->get('xlsfile');

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $app->enqueueMessage('Ошибка загрузки файла', 'error');
            $app->redirect(Route::_('index.php?option=com_estakadaimport', false));
            return false;
        }

        // Проверяем расширение файла
        $allowedExtensions = ['xls', 'xlsx'];
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        if (!in_array(strtolower($fileExtension), $allowedExtensions)) {
            $app->enqueueMessage('Недопустимый формат файла. Разрешены только .xls и .xlsx', 'error');
            $app->redirect(Route::_('index.php?option=com_estakadaimport', false));
            return false;
        }

        // Сохраняем файл во временную директорию
        $tmpPath = $app->get('tmp_path') . '/' . $file['name'];
        
        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            $app->enqueueMessage('Ошибка сохранения файла', 'error');
            $app->redirect(Route::_('index.php?option=com_estakadaimport', false));
            return false;
        }

        try {
            // Получаем модель и запускаем импорт с новым параметром
            $model = $this->getModel('Import');
            $result = $model->importFromExcel($tmpPath, $profileId, $selectedVendorId, $updatePricesOnly);

            // Удаляем временный файл
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }

            if ($result) {
                $message = $updatePricesOnly ? 'Цены успешно обновлены' : 'Импорт завершен успешно';
                $app->enqueueMessage($message, 'message');
            } else {
                $message = $updatePricesOnly ? 'Произошли ошибки при обновлении цен' : 'Произошли ошибки при импорте';
                $app->enqueueMessage($message, 'warning');
            }

        } catch (\Exception $e) {
            // Удаляем временный файл в случае ошибки
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            
            $app->enqueueMessage('Ошибка импорта: ' . $e->getMessage(), 'error');
        }

        $app->redirect(Route::_('index.php?option=com_estakadaimport', false));
    }

    public function analyzeSimple()
    {
        // Проверяем CSRF токен
        $this->checkToken();

        $app = Factory::getApplication();
        $user = Factory::getUser();

        Log::add(sprintf('ANALYZE: update_prices_only=%d', $updatePricesOnly), Log::DEBUG, 'com_estakadaimport');

        // Получаем параметры из запроса
        $selectedVendorId = $this->input->getInt('selected_vendor', null);
        $updatePricesOnly = $this->input->getBool('update_prices_only', false); // НОВЫЙ ПАРАМЕТР
        
        // Проверяем безопасность: только SuperUser может выбирать vendor
        if ($selectedVendorId && !$user->authorise('core.admin')) {
            echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
            $app->close();
        }

        // Обработка загрузки файла
        $file = $this->input->files->get('xlsfile');

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Ошибка загрузки файла']);
            $app->close();
        }

        // Проверяем расширение файла
        $allowedExtensions = ['xls', 'xlsx'];
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        
        if (!in_array(strtolower($fileExtension), $allowedExtensions)) {
            echo json_encode(['success' => false, 'message' => 'Недопустимый формат файла']);
            $app->close();
        }

        // Сохраняем файл во временную директорию
        $tmpPath = $app->get('tmp_path') . '/' . $file['name'];
        
        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            echo json_encode(['success' => false, 'message' => 'Ошибка сохранения файла']);
            $app->close();
        }

        try {
            // Получаем модель и анализируем файл
            $model = $this->getModel('Import');
            $result = $model->analyzeExcelFile($tmpPath);

            // Удаляем временный файл
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }

            // Добавляем информацию о режиме обновления цен
            $result['updatePricesOnly'] = $updatePricesOnly;

            echo json_encode(['success' => true, 'data' => $result]);

        } catch (\Exception $e) {
            // Удаляем временный файл в случае ошибки
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }

        $app->close();
    }

    public function fullProcess()
    {
        // Аналогичная логика для AJAX импорта
        $this->checkToken();

        $app = Factory::getApplication();
        $user = Factory::getUser();

        Log::add(sprintf('FULL PROCESS: update_prices_only=%d', $updatePricesOnly), Log::DEBUG, 'com_estakadaimport');

        // Получаем параметры из запроса
        $profileId = $this->input->getInt('import_profile', 0);
        $selectedVendorId = $this->input->getInt('selected_vendor', null);
        $updatePricesOnly = $this->input->getBool('update_prices_only', false); // НОВЫЙ ПАРАМЕТР
        
        // Проверяем безопасность
        if ($selectedVendorId && !$user->authorise('core.admin')) {
            echo json_encode(['success' => false, 'message' => 'Доступ запрещен']);
            $app->close();
        }

        // Обработка загрузки файла
        $file = $this->input->files->get('xlsfile');

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => 'Ошибка загрузки файла']);
            $app->close();
        }

        $tmpPath = $app->get('tmp_path') . '/' . $file['name'];
        
        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            echo json_encode(['success' => false, 'message' => 'Ошибка сохранения файла']);
            $app->close();
        }

        try {
            $model = $this->getModel('Import');
            
            // Передаем параметр updatePricesOnly в метод импорта
            $result = $model->importFromExcel($tmpPath, $profileId, $selectedVendorId, $updatePricesOnly);

            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }

            $message = $updatePricesOnly ? 
                ($result ? 'Обновление цен запущено' : 'Ошибка обновления цен') : 
                ($result ? 'Импорт запущен' : 'Ошибка импорта');

            echo json_encode(['success' => $result, 'message' => $message]);

        } catch (\Exception $e) {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }

        $app->close();
    }

     /**
     * Получение прогресса импорта для AJAX запросов
     */
    public function getProgress()
    {
        $app = Factory::getApplication();
        
        // Устанавливаем правильный content-type
        $app->setHeader('Content-Type', 'application/json', true);
        
        try {
            // Проверяем авторизацию
            $user = Factory::getUser();
            if ($user->guest) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Доступ запрещен'
                ]);
                $app->close();
                return;
            }

            // Получаем модель
            $model = $this->getModel('Import');
            
            if (!$model) {
                throw new \Exception('Модель Import не найдена');
            }

            // Проверяем существование метода в модели
            if (!method_exists($model, 'getImportProgress')) {
                throw new \Exception('Метод getImportProgress не найден в модели');
            }
            
            // Получаем прогресс из модели
            $progress = $model->getImportProgress();
            
            // Логируем для отладки
            Log::add('Progress data from model: ' . json_encode($progress), Log::DEBUG, 'com_estakadaimport');
            
            // Если импорт завершен
            if (isset($progress['completed']) && $progress['completed']) {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'completed' => true,
                        'current' => $progress['current'] ?? 0,
                        'total' => $progress['total'] ?? 0,
                        'currentImage' => $progress['currentImage'] ?? '',
                        'message' => 'Импорт завершен'
                    ]
                ]);
            } else {
                // Возвращаем текущий прогресс
                echo json_encode([
                    'success' => true,
                    'data' => $progress ?: [
                        'current' => 0,
                        'total' => 0,
                        'currentImage' => '',
                        'completed' => false
                    ]
                ]);
            }

        } catch (\Exception $e) {
            Log::add('Error in getProgress: ' . $e->getMessage(), Log::ERROR, 'com_estakadaimport');
            
            echo json_encode([
                'success' => false,
                'message' => 'Ошибка получения прогресса: ' . $e->getMessage(),
                'data' => [
                    'current' => 0,
                    'total' => 0,
                    'currentImage' => '',
                    'completed' => false
                ]
            ]);
        }
        
        $app->close();
    }
}