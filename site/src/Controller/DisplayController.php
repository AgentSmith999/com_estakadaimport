<?php
namespace Joomla\Component\Estakadaimport\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        $view = $this->input->get('view', 'import'); // По умолчанию import
        $this->input->set('view', $view);
        
        return parent::display($cachable, $urlparams);
    }
}