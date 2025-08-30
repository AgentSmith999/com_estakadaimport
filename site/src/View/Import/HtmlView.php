<?php
namespace Joomla\Component\Estakadaimport\Site\View\Import;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;

class HtmlView extends BaseHtmlView
{
    public function display($tpl = null)
    {
        try {
            $model = $this->getModel('import');
            
            // error_log('Model class: ' . get_class($model));
            
            $this->profiles = $model->getProfiles();
            error_log('Profiles: ' . print_r($this->profiles, true));
            
            $this->defaultProfileId = $model->getDefaultProfileId();
            error_log('Default profile ID: ' . $this->defaultProfileId);

            return parent::display($tpl);
            
        } catch (\Exception $e) {
            error_log('Error in HtmlView: ' . $e->getMessage());
            
            // Устанавливаем значения по умолчанию при ошибке
            $this->profiles = [];
            $this->defaultProfileId = 0;
            
            return parent::display($tpl);
        }
    }
}

?>