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
     * @var \JDatabaseDriver Подключение к БД
     */
    protected $db;

    /**
     * Конструктор модели
     */
    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->db = Factory::getDbo(); // Инициализация один раз
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
    public function getProfiles(): array
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
    public function getDefaultProfileId(): int
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
    protected function getProductIds(int $vendorId): array
    {
        $query = $this->db->getQuery(true)
            ->select('virtuemart_product_id')
            ->from('#__virtuemart_products')
            ->where('virtuemart_vendor_id = ' . (int)$vendorId)
            ->where('published = 1');
            
        return $this->db->setQuery($query)->loadColumn();
    }

    protected function getProducts(int $vendorId): array
    {
        $query = $this->db->getQuery(true)
            ->select('p.virtuemart_product_id, p.product_sku, l.product_name')
            ->from('#__virtuemart_products AS p')
            ->join('INNER', '#__virtuemart_products_ru_ru AS l ON p.virtuemart_product_id = l.virtuemart_product_id')
            ->where('p.virtuemart_vendor_id = ' . (int)$vendorId)
            ->where('p.published = 1');
        
        // Логирование запроса
        // Factory::getApplication()->enqueueMessage($query->dump(), 'notice');
        
        try {
            return $this->db->setQuery($query)->loadAssocList();
        } catch (Exception $e) {
            Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return [];
        }
    }

    protected function productBelongsToManufacturer(int $productId, int $selectedManufacturer, array $data): bool
    {
        // Если у нас есть direct mapping товар → производитель
        if (isset($data['manufacturers'][$productId])) {
            $productManufacturerId = $data['manufacturers'][$productId];
            return $productManufacturerId == $selectedManufacturer;
        }
        
        return false;
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
     * Получает артикулы товаров
     */
    protected function getProductSkus(int $vendorId): array
    {
        $query = $this->db->getQuery(true)
            ->select(['virtuemart_product_id', 'product_sku'])
            ->from('#__virtuemart_products')
            ->where('virtuemart_vendor_id = ' . (int)$vendorId);
            
        $result = $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
        
        // Преобразуем в простой массив [product_id => sku]
        $skus = [];
        foreach ($result as $productId => $row) {
            $skus[$productId] = $row['product_sku'] ?? '';
        }
        
        return $skus;
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
            
        $result = $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
        
        // Преобразуем в простой массив [id => name]
        $names = [];
        foreach ($result as $productId => $row) {
            $names[$productId] = $row['product_name'] ?? '';
        }
        
        return $names;
    }

    /**
     * Получает категории товаров
     */
    protected function getProductsCategories(array $productIds): array
    {
        if (empty($productIds)) return [];

        $query = $this->db->getQuery(true)
            ->select(['pc.virtuemart_product_id', 'cl.category_name'])
            ->from('#__virtuemart_product_categories AS pc')
            ->join('INNER', '#__virtuemart_categories AS c ON pc.virtuemart_category_id = c.virtuemart_category_id')
            ->join('INNER', '#__virtuemart_categories_ru_ru AS cl ON c.virtuemart_category_id = cl.virtuemart_category_id')
            ->where('pc.virtuemart_product_id IN (' . implode(',', $productIds) . ')');
            
        $result = $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
        
        // Преобразуем в простой массив
        $categories = [];
        foreach ($result as $productId => $row) {
            $categories[$productId] = $row['category_name'] ?? 'Без категории';
        }
        
        return $categories;
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
            
        $result = $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
        
        // Преобразуем в простой массив [product_id => manufacturer_name]
        $manufacturers = [];
        foreach ($result as $productId => $row) {
            $manufacturers[$productId] = $row['mf_name'] ?? 'Не указан';
        }
        
        return $manufacturers;
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
            
        $result = $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
        
        // Форматируем обе цены
        $prices = [];
        foreach ($result as $productId => $row) {
            $prices[$productId] = [
                'base' => $this->formatPrice($row['product_price'] ?? '0'),
                'override' => $this->formatPrice($row['product_override_price'] ?? '0')
            ];
        }
        
        return $prices;
    }

    /**
     * Форматирует цену
     */
    protected function formatPrice($priceValue): string
    {
        if (empty($priceValue) || $priceValue === '0') {
            return '0';
        }
        
        // Удаляем лишние нули и преобразуем в число
        $price = (float)preg_replace('/^0+/', '', $priceValue);
        
        // Проверяем, есть ли дробная часть
        if (floor($price) == $price) {
            return number_format($price, 0, '.', ' ');
        } else {
            return number_format($price, 2, '.', ' ');
        }
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
            
        $result = $this->db->setQuery($query)->loadAssocList('virtuemart_product_id');
        
        // Преобразуем в массив [product_id => array_of_images]
        $images = [];
        foreach ($result as $productId => $row) {
            $imageUrls = !empty($row['images']) ? explode('|', $row['images']) : [];
            $images[$productId] = $imageUrls;
        }
        
        return $images;
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
     * Получает кастомные поля для профиля
     */
    protected function getCustomFields(int $profileId): array
    {
        $query = $this->db->getQuery(true)
            ->select(['virtuemart_custom_id', 'custom_title', 'field_type'])
            ->from('#__virtuemart_customs')
            ->where('custom_parent_id = ' . (int)$profileId);
            
        $result = $this->db->setQuery($query)->loadAssocList();
        
        // Преобразуем в простой массив
        $fields = [];
        foreach ($result as $row) {
            $fields[] = [
                'virtuemart_custom_id' => $row['virtuemart_custom_id'],
                'custom_title' => $row['custom_title'],
                'field_type' => $row['field_type']
            ];
        }
        
        return $fields;
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
            
        $result = $this->db->setQuery($query)->loadAssocList();
        
        // Преобразуем в простой массив
        $fields = [];
        foreach ($result as $row) {
            $fields[] = [
                'virtuemart_custom_id' => $row['virtuemart_custom_id'],
                'custom_title' => $row['custom_title'],
                'field_type' => $row['field_type']
            ];
        }
        
        return $fields;
    }

    /**
     * Получает значения всех кастомных полей
     */
    protected function getAllCustomFieldsValues(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        // Получаем ID всех кастомных полей (обычные + универсальные)
        $customFields = array_merge(
            $this->getCustomFields($this->getDefaultProfileId()),
            $this->getUniversalFields()
        );
        
        $customIds = array_column($customFields, 'virtuemart_custom_id');
        
        // Создаем массив для идентификации плагинных полей
        $pluginFields = [];
        foreach ($customFields as $field) {
            if ($field['field_type'] === 'E') {
                $pluginFields[$field['virtuemart_custom_id']] = true;
            }
        }

        $query = $this->db->getQuery(true)
            ->select(['virtuemart_product_id', 'virtuemart_custom_id', 'customfield_value'])
            ->from('#__virtuemart_product_customfields')
            ->where('virtuemart_product_id IN (' . implode(',', $productIds) . ')')
            ->where('virtuemart_custom_id IN (' . implode(',', $customIds) . ')');
            
        $results = $this->db->setQuery($query)->loadAssocList();

        // Группируем данные
        $grouped = [];
        foreach ($results as $row) {
            $productId = $row['virtuemart_product_id'];
            $fieldId = $row['virtuemart_custom_id'];
            $value = $row['customfield_value'];
            
            if (!isset($grouped[$productId])) {
                $grouped[$productId] = [];
            }

            // Для плагинных полей (тип E) собираем массив значений
            if (isset($pluginFields[$fieldId])) {
                if (!isset($grouped[$productId][$fieldId])) {
                    $grouped[$productId][$fieldId] = [];
                }
                $grouped[$productId][$fieldId][] = $value;
            } else {
                $grouped[$productId][$fieldId] = $value;
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
     * Получает данные для экспорта
     */
    public function getDisplayData(int $profileId = 0, int $selectedManufacturer = 0, int $selectedCategory = 0): array
    {
        $user = Factory::getUser();
        $vendorId = $this->getVendorId($user->id);

        if (!$vendorId) {
            echo '<pre>No vendor found</pre>';
            return []; // Возвращаем пустой массив вместо исключения для AJAX
        }


        $profileId = $profileId ?: $this->getDefaultProfileId();
        
        // Получаем ID товаров продавца
        $productIds = $this->getProductIds($vendorId);
        
        // Основные данные товаров
        $products = $this->getProducts($vendorId);

         // Проверяем каждый метод отдельно
    // $categories = $this->getProductsCategories($productIds);
    // echo '<pre>Categories: '; print_r($categories); echo '</pre>';
        
        
        // Получаем все дополнительные данные
        $data = [
            'profiles' => $this->getProfiles(),
            'selectedProfile' => $profileId,
            'availableManufacturers' => $this->getUniqueManufacturers(), // ← Уникальные производители для селектора
            'availableCategories' => $this->getUniqueCategories(), // ← Уникальные категорий для селектора
            'products' => $products,
            'names' => $this->getProductsNames($productIds), // Теперь передаем productIds
            'categories' => $this->getProductsCategories($productIds),
            'manufacturers' => $this->getProductsManufacturers($productIds), // ← Привязка производителей к товарам
            'prices' => $this->getProductsPrices($productIds),
            'images' => $this->getProductsImages($productIds),
            'customFields' => $this->getCustomFields($profileId),
            'universalFields' => $this->getUniversalFields(),
            'customValues' => $this->getAllCustomFieldsValues($productIds),
            'fixedHeaders' => $this->getFixedHeaders(),
            'product_skus' => $this->getProductSkus($vendorId) // Новый метод для SKU - артикулы
        ];

        // Фильтрация по производителю
        if ($selectedManufacturer > 0) {
            $data = $this->filterByManufacturer($data, $selectedManufacturer);
        }

        // Фильтрация по категории
        if ($selectedCategory > 0) {
            $data = $this->filterByCategory($data, $selectedCategory);
        }

        return $data; // ← Return после фильтрации!
    }

    /**
     * Получает уникальные категории для текущего продавца
     */
    public function getUniqueCategories(): array
    {
        $user = Factory::getUser();
        $vendorId = $this->getVendorId($user->id);
        
        if (!$vendorId) {
            return [];
        }

        $productIds = $this->getProductIds($vendorId);
        
        if (empty($productIds)) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select(['DISTINCT c.virtuemart_category_id', 'cl.category_name'])
            ->from('#__virtuemart_product_categories pc')
            ->join('INNER', '#__virtuemart_categories c ON pc.virtuemart_category_id = c.virtuemart_category_id')
            ->join('INNER', '#__virtuemart_categories_ru_ru cl ON c.virtuemart_category_id = cl.virtuemart_category_id')
            ->join('INNER', '#__virtuemart_products p ON pc.virtuemart_product_id = p.virtuemart_product_id')
            ->where('pc.virtuemart_product_id IN (' . implode(',', $productIds) . ')')
            ->where('p.virtuemart_vendor_id = ' . (int)$vendorId)
            ->where('cl.category_name IS NOT NULL')
            ->where('cl.category_name != ""')
            ->order('cl.category_name ASC');
            
        $result = $this->db->setQuery($query)->loadAssocList();
        
        $availableCategories = [];
        foreach ($result as $row) {
            if (!empty($row['category_name'])) {
                $availableCategories[$row['virtuemart_category_id']] = $row['category_name'];
            }
        }
        
        return $availableCategories;
    }

    /**
     * Получает уникальных производителей для текущего продавца - под фильтр селектор
     */
    public function getUniqueManufacturers(): array
    {
        $user = Factory::getUser();
        $vendorId = $this->getVendorId($user->id);
        
        if (!$vendorId) {
            return [];
        }

        $productIds = $this->getProductIds($vendorId);
        
        if (empty($productIds)) {
            return [];
        }

        $query = $this->db->getQuery(true)
            ->select(['DISTINCT m.mf_name', 'm.virtuemart_manufacturer_id'])
            ->from('#__virtuemart_product_manufacturers pm')
            ->join('INNER', '#__virtuemart_manufacturers_ru_ru m ON pm.virtuemart_manufacturer_id = m.virtuemart_manufacturer_id')
            ->join('INNER', '#__virtuemart_products p ON pm.virtuemart_product_id = p.virtuemart_product_id')
            ->where('pm.virtuemart_product_id IN (' . implode(',', $productIds) . ')')
            ->where('p.virtuemart_vendor_id = ' . (int)$vendorId)
            ->where('m.mf_name IS NOT NULL')
            ->where('m.mf_name != ""')
            ->order('m.mf_name ASC');
            
        $result = $this->db->setQuery($query)->loadAssocList();
        
        $availableManufacturers = [];
        foreach ($result as $row) {
            if (!empty($row['mf_name'])) {
                $availableManufacturers[$row['virtuemart_manufacturer_id']] = $row['mf_name'];
            }
        }
        
        return $availableManufacturers;
    }

    /**
     * Фильтрует данные по массиву ID товаров
     */
    protected function filterDataByProductIds(array $data, array $filteredProductIds): array
    {
        if (empty($filteredProductIds)) {
            // Возвращаем пустые данные если нет товаров
            return [
                'profiles' => $data['profiles'] ?? [],
                'selectedProfile' => $data['selectedProfile'] ?? 0,
                'availableManufacturers' => $data['availableManufacturers'] ?? [],
                'availableCategories' => $data['availableCategories'] ?? [],
                'products' => [],
                'names' => [],
                'categories' => [],
                'manufacturers' => [],
                'prices' => [],
                'images' => [],
                'customFields' => $data['customFields'] ?? [],
                'universalFields' => $data['universalFields'] ?? [],
                'customValues' => [],
                'fixedHeaders' => $data['fixedHeaders'] ?? [],
                'product_skus' => []
            ];
        }
        
        // Фильтруем все массивы по ID товаров
        $data['products'] = array_values(array_filter($data['products'], function($product) use ($filteredProductIds) {
            return in_array($product['virtuemart_product_id'], $filteredProductIds);
        }));
        
        $data['names'] = array_intersect_key($data['names'], array_flip($filteredProductIds));
        $data['categories'] = array_intersect_key($data['categories'], array_flip($filteredProductIds));
        $data['manufacturers'] = array_intersect_key($data['manufacturers'], array_flip($filteredProductIds));
        $data['prices'] = array_intersect_key($data['prices'], array_flip($filteredProductIds));
        $data['images'] = array_intersect_key($data['images'], array_flip($filteredProductIds));
        $data['customValues'] = array_intersect_key($data['customValues'], array_flip($filteredProductIds));
        $data['product_skus'] = array_intersect_key($data['product_skus'], array_flip($filteredProductIds));
        
        return $data;
    }

    /**
     * Фильтрует данные по производителю
     */
    protected function filterByManufacturer(array $data, int $selectedManufacturer): array
    {
        if (empty($data['products']) || $selectedManufacturer <= 0) {
            return $data;
        }

        $selectedManufacturerName = $data['availableManufacturers'][$selectedManufacturer] ?? '';
        if (empty($selectedManufacturerName)) {
            return $data;
        }
        
        $filteredProductIds = [];
        
        foreach ($data['products'] as $product) {
            $productId = $product['virtuemart_product_id'];
            $productManufacturer = $data['manufacturers'][$productId] ?? '';
            
            if ($productManufacturer === $selectedManufacturerName) {
                $filteredProductIds[] = $productId;
            }
        }
        
        return $this->filterDataByProductIds($data, $filteredProductIds);
    }

    /**
     * Фильтрует данные по категории
     */
    protected function filterByCategory(array $data, int $selectedCategory): array
    {
        if (empty($data['products']) || $selectedCategory <= 0) {
            return $data;
        }

        $selectedCategoryName = $data['availableCategories'][$selectedCategory] ?? '';
        if (empty($selectedCategoryName)) {
            return $data;
        }
        
        $filteredProductIds = [];
        
        foreach ($data['products'] as $product) {
            $productId = $product['virtuemart_product_id'];
            $productCategory = $data['categories'][$productId] ?? '';
            
            if ($productCategory === $selectedCategoryName) {
                $filteredProductIds[] = $productId;
            }
        }
        
        return $this->filterDataByProductIds($data, $filteredProductIds);
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