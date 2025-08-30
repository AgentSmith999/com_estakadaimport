<?php
namespace Joomla\Component\Estakadaimport\Site\Model\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;

trait CategoryTrait
{
    /**
     * Обработка категорий с поддержкой иерархии и множественных категорий
     */
    protected function processCategories($categoryInfo, $productId)
    {
        // Log::add(sprintf('Обработка категорий для товара %d: %s', $productId, $categoryInfo), Log::DEBUG, 'com_estakadaimport');
        
        // Удаляем старые связи товара с категориями
        $this->removeProductCategories($productId);
        
        // Разделяем категории по точке с запятой
        $categories = $this->parseCategories($categoryInfo);

        // Log::add(sprintf('Распарсенные категории: %s', json_encode($categories)), Log::DEBUG, 'com_estakadaimport');
        
        $processedCategories = [];
        
        foreach ($categories as $categoryPath) {

            // Log::add(sprintf('Обработка пути категории: %s', $categoryPath), Log::DEBUG, 'com_estakadaimport');

            // Ищем или создаем категорию по пути
            $categoryId = $this->findOrCreateCategoryByPath($categoryPath);
            
            if ($categoryId) {
                // Добавляем товар в категорию (только если это конечная подкатегория)
                if ($this->isLeafCategory($categoryId)) {
                    $this->addProductToCategory($productId, $categoryId);
                    $processedCategories[] = $categoryId;
                    // Log::add(sprintf('Товар %d привязан к категории %d (%s)', $productId, $categoryId, $categoryPath), Log::INFO, 'com_estakadaimport');
                } else {
                    // Log::add(sprintf('Категория %d (%s) не является конечной - пропускаем', $categoryId, $categoryPath), Log::WARNING, 'com_estakadaimport');
                }
            }
        }
        
        if (empty($processedCategories)) {
            // Log::add(sprintf('Товар %d не привязан ни к одной категории', $productId), Log::WARNING, 'com_estakadaimport');
        }
    }

    /**
     * Парсинг строки категорий - улучшенная версия
     */
    protected function parseCategories($categoryInfo)
    {
        $categoryInfo = trim($categoryInfo);
        
        if (empty($categoryInfo)) {
            return [];
        }
        
        // Простое разделение по точке с запятой
        $categories = explode(';', $categoryInfo);
        
        // Очищаем каждый элемент от лишних пробелов
        $categories = array_map('trim', $categories);
        
        // Удаляем пустые элементы
        $categories = array_filter($categories);
        
        return $categories;
    }

    /**
     * Поиск или создание категории по пути
     */
    protected function findOrCreateCategoryByPath($categoryPath)
    {
        // Log::add(sprintf('Поиск категории по пути: %s', $categoryPath), Log::DEBUG, 'com_estakadaimport');

        $pathParts = explode('/', $categoryPath);
        $pathParts = array_map('trim', $pathParts);
        $pathParts = array_filter($pathParts);
        
        if (empty($pathParts)) {
            // Log::add('Путь категории пустой', Log::DEBUG, 'com_estakadaimport');
            return null;
        }

        // Log::add(sprintf('Части пути: %s', json_encode($pathParts)), Log::DEBUG, 'com_estakadaimport');
        
        $currentCategoryId = 0; // Начинаем с корня
        
        foreach ($pathParts as $categoryName) {
            // Log::add(sprintf('Шаг %d: Поиск категории "%s" с родителем %d', $index + 1, $categoryName, $currentCategoryId), Log::DEBUG, 'com_estakadaimport');

            $categoryId = $this->findCategoryByNameAndParent($categoryName, $currentCategoryId);
            
            if (!$categoryId) {
                // Категория не найдена - пропускаем весь путь
                // Log::add(sprintf('Категория "%s" не найдена (родитель: %d)', $categoryName, $currentCategoryId), Log::WARNING, 'com_estakadaimport');
                return null;
            }
            
            $currentCategoryId = $categoryId;
            // Log::add(sprintf('Найдена категория ID %d', $categoryId), Log::DEBUG, 'com_estakadaimport');
        }
        
        // Log::add(sprintf('Финальный ID категории: %d', $currentCategoryId), Log::DEBUG, 'com_estakadaimport');
        return $currentCategoryId;
    }

    /**
     * Поиск категории по имени и родителю
     */
    protected function findCategoryByNameAndParent($categoryName, $parentId = 0)
    {
        $query = $this->db->getQuery(true)
            ->select('c.virtuemart_category_id')
            ->from('#__virtuemart_categories AS c')
            ->join('INNER', '#__virtuemart_categories_ru_ru AS cl ON c.virtuemart_category_id = cl.virtuemart_category_id')
            ->where('cl.category_name = ' . $this->db->quote($categoryName))
            ->where('c.published = 1');
        
        if ($parentId == 0) {
            $query->where('c.category_parent_id = 0');
        } else {
            $query->where('c.category_parent_id = ' . (int)$parentId);
        }
        
        return $this->db->setQuery($query)->loadResult();
    }

    /**
     * Проверка, является ли категория конечной (не имеет дочерних)
     */
    protected function isLeafCategory($categoryId)
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__virtuemart_categories')
            ->where('category_parent_id = ' . (int)$categoryId)
            ->where('published = 1');
        
        $childCount = $this->db->setQuery($query)->loadResult();
        
        return $childCount == 0;
    }

    /**
     * Получение ID категории по номеру/ID/названию (для обратной совместимости)
     */
    protected function getCategoryId($categoryInfo)
    {
        $categoryInfo = trim($categoryInfo);
        
        if (empty($categoryInfo)) {
            return null;
        }
        
        // Log::add(sprintf('Поиск категории: %s', $categoryInfo), Log::DEBUG, 'com_estakadaimport');
        
        // Если передан числовой ID
        if (is_numeric($categoryInfo)) {
            $categoryId = $this->getCategoryById((int)$categoryInfo);
            if ($categoryId) {
                // Log::add(sprintf('Категория найдена по ID %d: %d', $categoryInfo, $categoryId), Log::DEBUG, 'com_estakadaimport');
                return $categoryId;
            }
        }
        
        // Если есть точка с запятой - это multiple категории, возвращаем первую найденную
        if (strpos($categoryInfo, ';') !== false) {
            // Log::add(sprintf('Обнаружены multiple категории: %s', $categoryInfo), Log::DEBUG, 'com_estakadaimport');
            
            $categories = $this->parseCategories($categoryInfo);
            foreach ($categories as $categoryPath) {
                $categoryId = $this->findOrCreateCategoryByPath($categoryPath);
                if ($categoryId) {
                    // Log::add(sprintf('Возвращаем первую найденную категорию: %d', $categoryId), Log::DEBUG, 'com_estakadaimport');
                    return $categoryId;
                }
            }
            return null;
        }
        
        // Пробуем найти как одиночную категорию
        $categoryId = $this->findCategoryByNameAndParent($categoryInfo, 0);
        if ($categoryId) {
            // Log::add(sprintf('Категория найдена по названию "%s": %d', $categoryInfo, $categoryId), Log::DEBUG, 'com_estakadaimport');
            return $categoryId;
        }
        
        // Пробуем найти по пути (для обратной совместимости)
        if (strpos($categoryInfo, '/') !== false) {
            $categoryId = $this->findOrCreateCategoryByPath($categoryInfo);
            if ($categoryId) {
                // Log::add(sprintf('Категория найдена по пути "%s": %d', $categoryInfo, $categoryId), Log::DEBUG, 'com_estakadaimport');
                return $categoryId;
            }
        }
        
        // Log::add(sprintf('Категория не найдена: %s', $categoryInfo), Log::WARNING, 'com_estakadaimport');
        return null;
    }

    /**
     * Поиск категории по ID
     */
    protected function getCategoryById($categoryId)
    {
        $query = $this->db->getQuery(true)
            ->select('c.virtuemart_category_id')
            ->from('#__virtuemart_categories AS c')
            ->join('INNER', '#__virtuemart_categories_ru_ru AS cl ON c.virtuemart_category_id = cl.virtuemart_category_id')
            ->where('c.virtuemart_category_id = ' . (int)$categoryId)
            ->where('c.published = 1');
        
        return $this->db->setQuery($query)->loadResult();
    }

    /**
     * Удаление всех связей товара с категориями
     */
    protected function removeProductCategories($productId)
    {
        $query = $this->db->getQuery(true)
            ->delete('#__virtuemart_product_categories')
            ->where('virtuemart_product_id = ' . (int)$productId);
        
        $this->db->setQuery($query)->execute();
        
        // Log::add(sprintf('Удалены старые связи товара %d с категориями', $productId), Log::DEBUG, 'com_estakadaimport');
    }

    /**
     * Добавление товара в категорию
     */
    protected function addProductToCategory($productId, $categoryId)
    {
        // Проверяем, не привязан ли уже товар к этой категории
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__virtuemart_product_categories')
            ->where('virtuemart_product_id = ' . (int)$productId)
            ->where('virtuemart_category_id = ' . (int)$categoryId);
        
        $exists = $this->db->setQuery($query)->loadResult();
        
        if ($exists) {
            // Log::add(sprintf('Товар %d уже привязан к категории %d', $productId, $categoryId), Log::DEBUG, 'com_estakadaimport');
            return true;
        }
        
        $productCategory = new \stdClass();
        $productCategory->virtuemart_product_id = $productId;
        $productCategory->virtuemart_category_id = $categoryId;
        
        try {
            $this->db->insertObject('#__virtuemart_product_categories', $productCategory);
            return true;
        } catch (\Exception $e) {
            // Log::add(sprintf('Ошибка привязки товара %d к категории %d: %s', $productId, $categoryId, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
            return false;
        }
    }
}