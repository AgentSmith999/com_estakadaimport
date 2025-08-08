<?php
namespace Joomla\Component\Estakadaimport\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Application\CMSApplication;
use Joomla\Database\DatabaseInterface;

class ExportModel extends BaseModel
{
    protected $db;
    protected $app;
    
    public function __construct($config = array())
    {
        parent::__construct($config);
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
        $this->app = Factory::getApplication();
    }

    /**
     * Получает данные для экспорта
     */
    public function getExportData(int $profileId = 0): array
    {
        $user = Factory::getUser();
        $vendorId = $this->getVendorId($user->id);

        if (!$vendorId) {
            throw new \RuntimeException('Пользователь не является продавцом', 403);
        }

        return [
            'profiles' => $this->getProfiles(),
            'selectedProfile' => $profileId ?: $this->getDefaultProfileId(),
            'products' => $this->getProductsData($vendorId, $profileId)
        ];
    }

    /**
     * Получает ID вендора для пользователя
     */
    protected function getVendorId(int $userId): ?int
    {
        $query = $this->db->getQuery(true)
            ->select('virtuemart_vendor_id')
            ->from('#__virtuemart_vmusers')
            ->where('virtuemart_user_id = ' . (int)$userId);
            
        return $this->db->setQuery($query)->loadResult();
    }

    /**
     * Получает список профилей экспорта
     */
    protected function getProfiles(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['virtuemart_custom_id', 'custom_title'])
            ->from('#__virtuemart_customs')
            ->where('field_type = ' . $this->db->quote('G'));
            
        return $this->db->setQuery($query)->loadAssocList('virtuemart_custom_id', 'custom_title');
    }

    /**
     * Получает ID профиля по умолчанию
     */
    protected function getDefaultProfileId(): int
    {
        $query = $this->db->getQuery(true)
            ->select('MIN(virtuemart_custom_id)')
            ->from('#__virtuemart_customs')
            ->where('field_type = ' . $this->db->quote('G'));
            
        return (int)$this->db->setQuery($query)->loadResult();
    }

    /**
     * Получает данные товаров для экспорта
     */
    protected function getProductsData(int $vendorId, int $profileId): array
    {
        // Основные данные товаров
        $products = $this->getProducts($vendorId);
        $productIds = array_column($products, 'virtuemart_product_id');

        // Дополнительные данные
        return [
            'products' => $products,
            'names' => $this->getProductsNames($productIds),
            'categories' => $this->getProductsCategories($productIds),
            'manufacturers' => $this->getProductsManufacturers($productIds),
            'prices' => $this->getProductsPrices($productIds),
            'images' => $this->getProductsImages($productIds),
            'custom_fields' => $this->getCustomFieldsValues($productIds, $profileId)
        ];
    }

    /**
     * Получает основные данные товаров
     */
    protected function getProducts(int $vendorId): array
    {
        $query = $this->db->getQuery(true)
            ->select([
                'p.virtuemart_product_id',
                'p.product_sku',
                'p.product_in_stock',
                'p.published'
            ])
            ->from('#__virtuemart_products p')
            ->where('p.virtuemart_vendor_id = ' . (int)$vendorId);
            
        return $this->db->setQuery($query)->loadAssocList();
    }

    /**
     * Получает названия товаров
     */
    protected function getProductsNames(array $productIds): array
    {
        if (empty($productIds)) return [];

        $query = $this->db->getQuery(true)
            ->select(['virtuemart_product_id', 'product_name'])
            ->from('#__virtuemart_products_ru_ru')
            ->where('virtuemart_product_id IN (' . implode(',', $productIds) . ')');
            
        return $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
    }

    /**
     * Получает категории товаров
     */
    protected function getProductsCategories(array $productIds): array
    {
        if (empty($productIds)) return [];

        $query = $this->db->getQuery(true)
            ->select([
                'pc.virtuemart_product_id', 
                'c.category_name'
            ])
            ->from('#__virtuemart_product_categories pc')
            ->join('LEFT', '#__virtuemart_categories_ru_ru c ON pc.virtuemart_category_id = c.virtuemart_category_id')
            ->where('pc.virtuemart_product_id IN (' . implode(',', $productIds) . ')');
            
        return $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
    }

    /**
     * Получает производителей товаров
     */
    protected function getProductsManufacturers(array $productIds): array
    {
        if (empty($productIds)) return [];

        $query = $this->db->getQuery(true)
            ->select([
                'pm.virtuemart_product_id', 
                'm.mf_name'
            ])
            ->from('#__virtuemart_product_manufacturers pm')
            ->join('LEFT', '#__virtuemart_manufacturers_ru_ru m ON pm.virtuemart_manufacturer_id = m.virtuemart_manufacturer_id')
            ->where('pm.virtuemart_product_id IN (' . implode(',', $productIds) . ')');
            
        return $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
    }

    /**
     * Получает цены товаров
     */
    protected function getProductsPrices(array $productIds): array
    {
        if (empty($productIds)) return [];

        $query = $this->db->getQuery(true)
            ->select([
                'virtuemart_product_id',
                'product_price',
                'product_override_price'
            ])
            ->from('#__virtuemart_product_prices')
            ->where('virtuemart_product_id IN (' . implode(',', $productIds) . ')');
            
        return $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
    }

    /**
     * Получает изображения товаров
     */
    protected function getProductsImages(array $productIds): array
    {
        if (empty($productIds)) return [];

        $query = $this->db->getQuery(true)
            ->select([
                'pm.virtuemart_product_id',
                'GROUP_CONCAT(m.file_url SEPARATOR "|") AS images'
            ])
            ->from('#__virtuemart_product_medias pm')
            ->join('INNER', '#__virtuemart_medias m ON pm.virtuemart_media_id = m.virtuemart_media_id')
            ->where('pm.virtuemart_product_id IN (' . implode(',', $productIds) . ')')
            ->group('pm.virtuemart_product_id');
            
        return $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
    }

    /**
     * Получает значения кастомных полей
     */
    protected function getCustomFieldsValues(array $productIds, int $profileId): array
    {
        if (empty($productIds)) return [];

        // Получаем ID кастомных полей для профиля
        $customFields = $this->getProfileCustomFields($profileId);
        $customFieldIds = array_column($customFields, 'virtuemart_custom_id');

        if (empty($customFieldIds)) return [];

        $query = $this->db->getQuery(true)
            ->select([
                'virtuemart_product_id',
                'virtuemart_custom_id',
                'customfield_value'
            ])
            ->from('#__virtuemart_product_customfields')
            ->where('virtuemart_product_id IN (' . implode(',', $productIds) . ')')
            ->where('virtuemart_custom_id IN (' . implode(',', $customFieldIds) . ')');
            
        $results = $this->db->setQuery($query)->loadObjectList();
        
        // Группируем по product_id => [custom_id => value]
        $grouped = [];
        foreach ($results as $row) {
            $grouped[$row->virtuemart_product_id][$row->virtuemart_custom_id] = $row->customfield_value;
        }
        
        return $grouped;
    }

    /**
     * Получает кастомные поля профиля
     */
    protected function getProfileCustomFields(int $profileId): array
    {
        $query = $this->db->getQuery(true)
            ->select(['virtuemart_custom_id', 'custom_title'])
            ->from('#__virtuemart_customs')
            ->where('custom_parent_id = ' . (int)$profileId);
            
        return $this->db->setQuery($query)->loadObjectList();
    }

    /**
     * Генерирует Excel файл и отправляет его в браузер
     */
    public function exportToExcel(array $exportData): void
    {
        // Ваш код преобразования данных в Excel через SheetJS
        $this->app->setHeader('Content-Type', 'application/vnd.ms-excel');
        $this->app->setHeader('Content-Disposition', 'attachment; filename="export_' . date('Y-m-d') . '.xls"');
        
        // Здесь должен быть ваш JavaScript код экспорта через SheetJS
        // В реальной реализации это будет отдельный шаблон
        echo "Excel export would be generated here";
        $this->app->close();
    }
}