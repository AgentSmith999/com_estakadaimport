<?php
namespace Joomla\Component\Estakadaimport\Site\View\Export;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Factory;
use Joomla\CMS\Toolbar\ToolbarHelper;

class HtmlView extends BaseHtmlView
{
    protected $profiles = []; // @var array Профили для экспорта
    protected $selectedProfile = 0; // @var int ID выбранного профиля

    public function display($tpl = null)
    {
        // Получаем данные из модели
        $this->setDocumentTitle('Экспорт данных');
        $model = $this->getModel();
        $app = Factory::getApplication();

        // Получаем и сразу сохраняем в свойства
        $profileId = $app->input->getInt('export_profile', 0);
        $selectedManufacturer = $app->input->getInt('manufacturer', 0);
        $selectedCategory = $app->input->getInt('category', 0);
        
        // Получаем данные с фильтрацией
        $data = $model->getDisplayData($profileId, $selectedManufacturer, $selectedCategory);

        // Передаем данные в свойства View для шаблона
        $this->profiles = $data['profiles'] ?? [];
        $this->availableManufacturers = $data['availableManufacturers'] ?? [];
        $this->availableCategories = $data['availableCategories'] ?? [];
        $this->selectedProfile = $profileId; // ← Передаем в свойство
        $this->selectedManufacturer = $selectedManufacturer; // ← Передаем в свойство

        $this->items = $data['products'] ?? [];
        $this->product_ids = array_column($data['products'] ?? [], 'virtuemart_product_id');
        
        // Передаем остальные необходимые переменные
        $this->fixed_headers = $data['fixedHeaders'] ?? [];
        $this->product_categories = $data['categories'] ?? [];
        $this->product_manufacturers = $data['manufacturers'] ?? [];
        $this->product_names = $data['names'] ?? [];
        $this->product_prices = $data['prices'] ?? [];
        $this->product_images = $data['images'] ?? [];
        $this->all_custom_values = $data['customValues'] ?? [];
        $this->custom_titles = array_column($data['customFields'] ?? [], 'custom_title');
        $this->virtuemart_custom_ids = array_column($data['customFields'] ?? [], 'virtuemart_custom_id');
        $this->universal_custom_titles = array_column($data['universalFields'] ?? [], 'custom_title');
        $this->universal_custom_ids = array_column($data['universalFields'] ?? [], 'virtuemart_custom_id');
        
        parent::display($tpl);
    }
}