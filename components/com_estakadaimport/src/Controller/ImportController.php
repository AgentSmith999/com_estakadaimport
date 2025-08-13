<?php
namespace Joomla\Component\Estakadaimport\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Filesystem\File;
use Joomla\CMS\Filesystem\Path;

class ImportController extends BaseController
{
    public function display($cachable = false, $urlparams = array())
    {
        $view = $this->getView('Import', 'html');
        $view->setModel($this->getModel('Import'), true);
        $view->display();
    }

    public function upload()
    {
        $this->checkToken();

        $app = Factory::getApplication();
        $input = $app->input;
        $file = $input->files->get('xlsfile');
        $categoryId = $input->getInt('category_id', 0);

        try {
            if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Ошибка загрузки файла');
            }

            // Валидация расширения
            $ext = strtolower(File::getExt($file['name']));
            if (!in_array($ext, ['xls', 'xlsx'])) {
                throw new \RuntimeException('Недопустимый формат файла. Разрешены только .xls и .xlsx');
            }

            // Сохранение во временную папку
            $tmpPath = JPATH_SITE . '/tmp/' . uniqid('import_') . '.' . $ext;
            if (!File::upload($file['tmp_name'], $tmpPath)) {
                throw new \RuntimeException('Не удалось сохранить файл');
            }

            // Импорт
            $model = $this->getModel('Import');
            $result = $model->importProducts($tmpPath, $categoryId);

            // Формируем результат
            $message = sprintf(
                "Импорт завершен. Успешно: %d, Ошибки: %d, Пропущено: %d",
                $result['success'],
                $result['errors'],
                $result['skipped']
            );

            $app->enqueueMessage($message, 'info');

        } catch (\Exception $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        // Очистка
        if (isset($tmpPath) && file_exists($tmpPath)) {
            File::delete($tmpPath);
        }

        $this->setRedirect(Route::_('index.php?option=com_estakadaimport&view=import', false));
    }
}