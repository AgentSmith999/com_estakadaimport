<?php
namespace Joomla\Component\Estakadaimport\Site\View\Export;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

class HtmlView extends BaseHtmlView
{
    /**
     * Данные для экспорта
     * @var array
     */
    protected $exportData = [];

    /**
     * Метод отображения
     */
    public function display($tpl = null)
    {
        // 1. Получаем данные из модели
        $this->exportData = $this->get('ExportData');
        
        // 2. Распаковываем данные в свойства view
        $this->prepareData();
        
        // 3. Проверяем на ошибки
        if (count($errors = $this->get('Errors'))) {
            throw new \Exception(implode("\n", $errors), 500);
        }
        
        parent::display($tpl);
    }

    /**
     * Подготавливает данные для шаблона
     */
    protected function prepareData()
    {
        // Распаковываем массив в свойства объекта
        foreach ($this->exportData as $key => $value) {
            $this->$key = $value;
        }
    }
}