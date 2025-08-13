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
        $this->categories = $this->get('Categories');
        $this->importResult = Factory::getApplication()->input->getString('import_result');

        // Устанавливаем заголовок
        $this->setDocument();

        // Проверяем на ошибки
        if (count($errors = $this->get('Errors'))) {
            throw new \Exception(implode("\n", $errors), 500);
        }

        parent::display($tpl);
    }

    /**
     * Устанавливает мета-данные документа
     */
    protected function setDocument()
    {
        $document = Factory::getDocument();
        $document->setTitle('Импорт товаров - ' . Factory::getConfig()->get('sitename'));
    }

    /**
     * Добавляет кнопки тулбара (если используется административная часть)
     */
    protected function addToolbar()
    {
        ToolbarHelper::title('Импорт товаров', 'upload');
        ToolbarHelper::custom('import.upload', 'upload', '', 'Импортировать', false);
    }

    public function display($tpl = null)
    {
        // Подключаем CSS
        $wa = $this->document->getWebAssetManager();
        $wa->registerAndUseStyle(
            'com_estakadaimport.styles', 
            'components/com_estakadaimport/assets/css/estakadaimport.css'
        );
        
        parent::display($tpl);
    }
}