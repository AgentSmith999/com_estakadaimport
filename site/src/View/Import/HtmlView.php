<?php
namespace Joomla\Component\Estakadaimport\Site\View\Import;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    /**
     * @var array Категории для select
     */
    protected $categories = [];

    /**
     * @var string Результат импорта
     */
    protected $importResult;

    /**
     * Отображение
     */
    public function display($tpl = null)
    {
        // Получаем данные из модели
        $this->setDocumentTitle('Импорт данных');
        // $this->categories = $this->get('Categories');
        // $this->importResult = Factory::getApplication()->input->getString('import_result');

        // Устанавливаем заголовок
        // $this->setDocument();

        // Проверяем на ошибки
        if (count($errors = $this->get('Errors'))) {
            throw new \Exception(implode("\n", $errors), 500);
        }

        // Подключаем CSS
        $wa = $this->document->getWebAssetManager();
        $wa->registerAndUseStyle(
            'com_estakadaimport.styles', 
            'components/com_estakadaimport/assets/css/estakadaimport.css'
        );

        parent::display($tpl);
    }

}