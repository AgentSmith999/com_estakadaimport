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
}
?>