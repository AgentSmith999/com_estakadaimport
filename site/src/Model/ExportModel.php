<?php
namespace Joomla\Component\Estakadaimport\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Application\CMSApplication;
use Joomla\Database\DatabaseInterface;

class ExportModel extends BaseModel
{
    protected $productIds = [];

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
     * Получает данные для экспорта
     */
    public function getExportData(int $profileId = 0): array
    {
        $user = Factory::getUser();
        $vendorId = $this->getVendorId($user->id);

        if (!$vendorId) {
            throw new \RuntimeException(Text::_('COM_ESTAKADAIMPORT_ERROR_NOT_VENDOR'), 403);
        }

        $profileId = $profileId ?: $this->getDefaultProfileId();
        
        // Основные данные товаров
        $products = $this->getProducts($vendorId);
        $this->productIds = array_column($products, 'virtuemart_product_id');

        // Получаем все дополнительные данные
        return [
            'profiles' => $this->getProfiles(),
            'selectedProfile' => $profileId,
            'products' => $products,
            'names' => $this->getProductsNames(),
            'categories' => $this->getProductsCategories(),
            'manufacturers' => $this->getProductsManufacturers(),
            'prices' => $this->getProductsPrices(),
            'images' => $this->getProductsImages(),
            'customFields' => $this->getCustomFields($profileId),
            'universalFields' => $this->getUniversalFields(),
            'customValues' => $this->getAllCustomFieldsValues(),
            'fixedHeaders' => $this->getFixedHeaders()
        ];
    }

    /**
     * Фиксированные заголовки таблицы
     */
    protected function getFixedHeaders(): array
    {
        return [
            'Категория (Номер/ID/Название)',
            'Производитель',
            'Артикул',
            'Наименование товара',
            'Цена',
            'Модификатор цены',
            'Изображение'
        ];
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
     * Получает кастомные поля для профиля
     */
    protected function getCustomFields(int $profileId): array
    {
        $query = $this->db->getQuery(true)
            ->select(['virtuemart_custom_id', 'custom_title', 'field_type'])
            ->from('#__virtuemart_customs')
            ->where('custom_parent_id = ' . (int)$profileId);
            
        return $this->db->setQuery($query)->loadObjectList();
    }

    /**
     * Получает универсальные кастомные поля
     */
    protected function getUniversalFields(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['virtuemart_custom_id', 'custom_title', 'field_type'])
            ->from('#__virtuemart_customs')
            ->where('custom_parent_id = 0')
            ->where('field_type IN (' . $this->db->quote('S') . ',' . $this->db->quote('E') . ')');
            
        return $this->db->setQuery($query)->loadObjectList();
    }

    /**
     * Получает значения всех кастомных полей
     */
    protected function getAllCustomFieldsValues(): array
    {
        if (empty($this->productIds)) {
            return [];
        }

        // Получаем ID всех кастомных полей (обычные + универсальные)
        $customFields = array_merge(
            $this->getCustomFields($this->getDefaultProfileId()),
            $this->getUniversalFields()
        );
        
        $customIds = array_column($customFields, 'virtuemart_custom_id');
        $pluginFields = array_filter($customFields, fn($f) => $f->field_type === 'E');

        $query = $this->db->getQuery(true)
            ->select(['virtuemart_product_id', 'virtuemart_custom_id', 'customfield_value'])
            ->from('#__virtuemart_product_customfields')
            ->where('virtuemart_product_id IN (' . implode(',', $this->productIds) . ')')
            ->where('virtuemart_custom_id IN (' . implode(',', $customIds) . ')');
            
        $results = $this->db->setQuery($query)->loadObjectList();

        // Группируем данные
        $grouped = [];
        foreach ($results as $row) {
            $productId = $row->virtuemart_product_id;
            $fieldId = $row->virtuemart_custom_id;
            
            if (!isset($grouped[$productId])) {
                $grouped[$productId] = [];
            }

            // Для плагинных полей (тип E) собираем массив значений
            if (isset($pluginFields[$fieldId])) {
                if (!isset($grouped[$productId][$fieldId])) {
                    $grouped[$productId][$fieldId] = [];
                }
                $grouped[$productId][$fieldId][] = $row->customfield_value;
            } else {
                $grouped[$productId][$fieldId] = $row->customfield_value;
            }
        }

        // Объединяем множественные значения для плагинных полей
        foreach ($grouped as &$productValues) {
            foreach ($productValues as $fieldId => &$value) {
                if (isset($pluginFields[$fieldId]) && is_array($value)) {
                    $value = implode('|', $value);
                }
            }
        }

        return $grouped;
    }

    /**
     * Форматирует изображения для вывода
     */
    public function formatImages(array $images): string
    {
        if (empty($images)) {
            return Text::_('COM_ESTAKADAIMPORT_NO_IMAGES');
        }

        $baseUrl = Uri::root();
        $links = [];
        
        foreach ($images as $img) {
            $cleanPath = ltrim($img, '/');
            $fullUrl = $baseUrl . $cleanPath;
            $links[] = '<a href="' . htmlspecialchars($fullUrl) . '" target="_blank">'
                     . htmlspecialchars(basename($img)) . '</a>';
        }
        
        return implode(' | ', $links);
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