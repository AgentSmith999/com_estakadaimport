<?php
namespace Joomla\Component\Estakadaimport\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = array())
    {
        return parent::display($cachable, $urlparams);
    }
    
    public function import()
    {
        // Проверка токена
        $this->checkToken();
        
        $model = $this->getModel('Import');
        $result = $model->import();
        
        if ($result) {
            $this->app->enqueueMessage('Товары успешно импортированы', 'message');
        } else {
            $this->app->enqueueMessage('Ошибка при импорте', 'error');
        }
        
        $this->setRedirect('index.php?option=com_estakadaimport');
    }
}
?>