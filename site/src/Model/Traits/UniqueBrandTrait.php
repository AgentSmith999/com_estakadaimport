<?php
namespace Joomla\Component\Estakadaimport\Site\Model\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;

trait UniqueBrandTrait
{
    /**
     * Проверяет, не занят ли производитель в категории другим продавцом
     */
    protected function checkManufacturerCategoryOwnership($manufacturerId, $categoryId, $vendorId)
    {
        Log::add(sprintf('checkManufacturerCategoryOwnership: vendorId=%d', $vendorId), Log::DEBUG, 'com_estakadaimport');

        Log::add(sprintf('Checking manufacturer %d in category %d for vendor %d', $manufacturerId, $categoryId, $vendorId), Log::DEBUG, 'com_estakadaimport');
        
        $query = $this->db->getQuery(true)
            ->select('virtuemart_vendor_id')
            ->from('#__virtuemart_manufacturer_vendor')
            ->where('virtuemart_manufacturer_id = ' . (int)$manufacturerId)
            ->where('virtuemart_category_id = ' . (int)$categoryId);
        
        $existingVendorId = $this->db->setQuery($query)->loadResult();
        
        // Если запись существует и принадлежит другому продавцу
        if ($existingVendorId && $existingVendorId != $vendorId) {
            return false;
        }
        
        return true;
    }

    /**
     * Записывает связь производитель-категория-продавец
     */
    protected function addManufacturerCategoryVendor($manufacturerId, $categoryId, $vendorId)
    {
        // Сначала проверяем, нет ли уже такой записи
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__virtuemart_manufacturer_vendor')
            ->where('virtuemart_manufacturer_id = ' . (int)$manufacturerId)
            ->where('virtuemart_category_id = ' . (int)$categoryId)
            ->where('virtuemart_vendor_id = ' . (int)$vendorId);
        
        $exists = $this->db->setQuery($query)->loadResult();
        
        if (!$exists) {
            $record = new \stdClass();
            $record->virtuemart_manufacturer_id = $manufacturerId;
            $record->virtuemart_category_id = $categoryId;
            $record->virtuemart_vendor_id = $vendorId;
            
            try {
                $this->db->insertObject('#__virtuemart_manufacturer_vendor', $record);
                Log::add(sprintf('Added manufacturer %d - category %d - vendor %d', 
                    $manufacturerId, $categoryId, $vendorId), Log::DEBUG, 'com_estakadaimport');
                return true;
            } catch (\Exception $e) {
                Log::add('Error adding manufacturer-vendor-category relation: ' . $e->getMessage(), 
                    Log::ERROR, 'com_estakadaimport');
                return false;
            }
        }
        
        return true;
    }

    /**
     * Получает название производителя по ID
     */
    protected function getManufacturerName($manufacturerId)
    {
        $query = $this->db->getQuery(true)
            ->select('mf_name')
            ->from('#__virtuemart_manufacturers_ru_ru')
            ->where('virtuemart_manufacturer_id = ' . (int)$manufacturerId);
        
        return $this->db->setQuery($query)->loadResult() ?: 'Неизвестный производитель';
    }

    /**
     * Получает название категории по ID
     */
    protected function getCategoryName($categoryId)
    {
        $query = $this->db->getQuery(true)
            ->select('category_name')
            ->from('#__virtuemart_categories_ru_ru')
            ->where('virtuemart_category_id = ' . (int)$categoryId);
        
        return $this->db->setQuery($query)->loadResult() ?: 'Неизвестная категория';
    }

    /**
     * Получает название продавца по ID
     */
    protected function getVendorName($vendorId)
    {
        $query = $this->db->getQuery(true)
            ->select('vendor_name')
            ->from('#__virtuemart_vendors')
            ->where('virtuemart_vendor_id = ' . (int)$vendorId);
        
        return $this->db->setQuery($query)->loadResult() ?: 'Неизвестный продавец';
    }

    /**
     * Получает категории товара
     */
    protected function getProductCategories($productId)
    {
        $query = $this->db->getQuery(true)
            ->select('virtuemart_category_id')
            ->from('#__virtuemart_product_categories')
            ->where('virtuemart_product_id = ' . (int)$productId);
        
        return $this->db->setQuery($query)->loadColumn();
    }

    /**
     * Проверяет и регистрирует производителя для всех категорий товара
     */
    protected function validateAndRegisterManufacturer($manufacturerId, $productId, $vendorId)
    {
        Log::add(sprintf('validateAndRegisterManufacturer: vendorId=%d', $vendorId), Log::DEBUG, 'com_estakadaimport');
        
        // Получаем категории товара
        $categoryIds = $this->getProductCategories($productId);
        
        if (empty($categoryIds)) {
            throw new \Exception('Товар не имеет категорий для проверки производителя');
        }
        
        // Проверяем каждую категорию на занятость производителя
        foreach ($categoryIds as $categoryId) {
            if (!$this->checkManufacturerCategoryOwnership($manufacturerId, $categoryId, $vendorId)) {
                $manufacturerName = $this->getManufacturerName($manufacturerId);
                $categoryName = $this->getCategoryName($categoryId);
                
                throw new \Exception(sprintf(
                    'Производитель "%s" уже используется другим продавцом в категории "%s". ' .
                    'Вы не можете использовать этого производителя в данной категории.',
                    $manufacturerName,
                    $categoryName
                ));
            }
        }
        
        // Записываем связи производитель-категория-продавец
        foreach ($categoryIds as $categoryId) {
            $this->addManufacturerCategoryVendor($manufacturerId, $categoryId, $vendorId);
        }
        
        return true;
    }
}