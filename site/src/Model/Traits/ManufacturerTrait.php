<?php
namespace Joomla\Component\Estakadaimport\Site\Model\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;
use Joomla\CMS\Application\ApplicationHelper;

trait ManufacturerTrait
{
    /**
     * Получение или создание производителя
     */
    protected function getOrCreateManufacturer($manufacturerName, $userId)
    {
        $manufacturerName = trim($manufacturerName);
        
        if (empty($manufacturerName)) {
            return null;
        }
        
        // Log::add(sprintf('Поиск производителя: %s', $manufacturerName), Log::DEBUG, 'com_estakadaimport');
        
        // Сначала пытаемся найти существующего производителя
        $manufacturerId = $this->getManufacturerByName($manufacturerName);
        
        if ($manufacturerId) {
            // Log::add(sprintf('Производитель найден: %s (ID: %d)', $manufacturerName, $manufacturerId), Log::DEBUG, 'com_estakadaimport');
            return $manufacturerId;
        }
        
        // Если не найден - создаем нового
        // Log::add(sprintf('Создание нового производителя: %s', $manufacturerName), Log::INFO, 'com_estakadaimport');
        
        return $this->createManufacturer($manufacturerName, $userId);
    }

    /**
     * Поиск производителя по названию
     */
    protected function getManufacturerByName($manufacturerName)
    {
        $query = $this->db->getQuery(true)
            ->select('m.virtuemart_manufacturer_id')
            ->from('#__virtuemart_manufacturers AS m')
            ->join('INNER', '#__virtuemart_manufacturers_ru_ru AS ml ON m.virtuemart_manufacturer_id = ml.virtuemart_manufacturer_id')
            ->where('ml.mf_name = ' . $this->db->quote($manufacturerName))
            ->where('m.published = 1');
        
        return $this->db->setQuery($query)->loadResult();
    }

    /**
     * Создание нового производителя
     */
    protected function createManufacturer($manufacturerName, $userId)
    {
        try {
            // Создаем slug из названия
            $slug = ApplicationHelper::stringURLSafe($manufacturerName);
            if (empty($slug)) {
                $slug = ApplicationHelper::stringURLSafe(uniqid());
            }
            
            // Основная таблица производителей
            $manufacturer = new \stdClass();
            $manufacturer->hits = 0;
            $manufacturer->published = 1;
            $manufacturer->created_by = $userId;
            $manufacturer->modified_by = $userId;
            $manufacturer->locked_by = 0;
            
            $this->db->insertObject('#__virtuemart_manufacturers', $manufacturer);
            $manufacturerId = $this->db->insertid();
            
            // Языковая таблица производителей
            $manufacturerLang = new \stdClass();
            $manufacturerLang->virtuemart_manufacturer_id = $manufacturerId;
            $manufacturerLang->mf_name = $manufacturerName;
            $manufacturerLang->slug = $slug;
            $manufacturerLang->mf_desc = '';
            $manufacturerLang->metadesc = '';
            $manufacturerLang->metakey = '';
            
            $this->db->insertObject('#__virtuemart_manufacturers_ru_ru', $manufacturerLang);
            
            // Log::add(sprintf('Создан новый производитель: %s (ID: %d)', $manufacturerName, $manufacturerId), Log::INFO, 'com_estakadaimport');
            
            return $manufacturerId;
            
        } catch (\Exception $e) {
            // Log::add(sprintf('Ошибка создания производителя %s: %s', $manufacturerName, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
            return null;
        }
    }

    /**
     * Обработка привязки производителя к товару
     */
    protected function processManufacturer($manufacturerId, $productId)
    {
        // Log::add(sprintf('Привязка товара %d к производителю %d', $productId, $manufacturerId), Log::DEBUG, 'com_estakadaimport');
        
        // Удаляем старые связи товара с производителями
        $this->removeProductManufacturers($productId);
        
        // Создаем новую связь
        $this->addProductToManufacturer($productId, $manufacturerId);
        
        // Log::add(sprintf('Товар %d привязан к производителю %d', $productId, $manufacturerId), Log::INFO, 'com_estakadaimport');
    }

    /**
     * Удаление всех связей товара с производителями
     */
    protected function removeProductManufacturers($productId)
    {
        $query = $this->db->getQuery(true)
            ->delete('#__virtuemart_product_manufacturers')
            ->where('virtuemart_product_id = ' . (int)$productId);
        
        $this->db->setQuery($query)->execute();
        
        // Log::add(sprintf('Удалены старые связи товара %d с производителями', $productId), Log::DEBUG, 'com_estakadaimport');
    }

    /**
     * Добавление товара к производителю
     */
    protected function addProductToManufacturer($productId, $manufacturerId)
    {
        $productManufacturer = new \stdClass();
        $productManufacturer->virtuemart_product_id = $productId;
        $productManufacturer->virtuemart_manufacturer_id = $manufacturerId;
        
        try {
            $this->db->insertObject('#__virtuemart_product_manufacturers', $productManufacturer);
            return true;
        } catch (\Exception $e) {
            // Log::add(sprintf('Ошибка привязки товара %d к производителю %d: %s', $productId, $manufacturerId, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
            return false;
        }
    }
}