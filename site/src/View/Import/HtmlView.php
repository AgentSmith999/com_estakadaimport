<?php
namespace Joomla\Component\Estakadaimport\Site\View\Import;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

class HtmlView extends BaseHtmlView
{
    /**
     * Display the view
     */
    public function display($tpl = null)
    {
        $this->setLayout('default');
        parent::display($tpl);
    }

    /**
     * Получение категорий (оставлено для возможного будущего использования)
     */
    public function getCategories()
    {
        // Пока возвращаем пустой массив, так как категории не используются
        return [];
    }
}
?>