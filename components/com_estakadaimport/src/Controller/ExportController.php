<?php
namespace Joomla\Component\Estakadaimport\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Factory;

class ExportController extends BaseController
{
    public function display($cachable = false, $urlparams = array())
    {
        $profileId = $this->input->getInt('export_profile', 0);
        $model = $this->getModel('Export');
        
        try {
            $exportData = $model->getExportData($profileId);
            $view = $this->getView('Export', 'html');
            $view->setModel($model, true);
            $view->data = $exportData;
            $view->display();
        } catch (\Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            $this->setRedirect('index.php?option=com_estakadaimport&view=export');
        }
    }

    /**
     * AJAX-метод для загрузки таблицы
     * Доступ: index.php?option=com_estakadaimport&task=export.loadTable&format=raw&profile=ID
     */
    public function loadTable()
    {
        // Разрешаем только AJAX-запросы
        if (!Factory::getApplication()->input->isAjax()) {
            throw new \RuntimeException('Доступ запрещён', 403);
        }

        try {
            $profileId = $this->input->getInt('profile', 0);
            $model = $this->getModel('Export');
            $exportData = $model->getExportData($profileId);

            // Рендерим только таблицу
            $view = $this->getView('Export', 'html');
            $view->setModel($model, true);
            $view->data = $exportData;
            $view->setLayout('raw'); // Используем layout=raw для чистого HTML
            
            ob_start();
            $view->display();
            $html = ob_get_clean();

            echo $html;
        } catch (\Exception $e) {
            http_response_code(500);
            echo '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
        }

        Factory::getApplication()->close();
    }
}