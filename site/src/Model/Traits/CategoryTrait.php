<?php
namespace Joomla\Component\Estakadaimport\Site\Model\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

trait CategoryTrait
{
    /**
     * Обработка категорий с поддержкой иерархии и множественных категорий
     */
    protected function processCategories($categoryInfo, $productId)
    {
        // Log::add(sprintf('Обработка категорий для товара %d: %s', $productId, $categoryInfo), Log::DEBUG, 'com_estakadaimport');
        
        if (empty($categoryInfo)) {
            throw new \Exception('Не указана категория для товара');
        }
        
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
            throw new \Exception('Товар не привязан ни к одной категории');
        }
        
        return $processedCategories;
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
        
        foreach ($pathParts as $index => $categoryName) {
            // Log::add(sprintf('Шаг %d: Поиск категории "%s" с родителем %d', $index + 1, $categoryName, $currentCategoryId), Log::DEBUG, 'com_estakadaimport');

            $categoryId = $this->findCategoryByNameAndParent($categoryName, $currentCategoryId);
            
            if (!$categoryId) {
                // Категория не найдена - проверяем права пользователя
                $user = Factory::getUser();
                if ($this->isSuperUser($user->id)) {
                    // SuperUser может создавать категории
                    $categoryId = $this->createCategory($categoryName, $currentCategoryId);
                    if ($categoryId) {
                        // Log::add(sprintf('Создана новая категория "%s" (ID: %d, родитель: %d)', $categoryName, $categoryId, $currentCategoryId), Log::INFO, 'com_estakadaimport');
                    } else {
                        // Log::add(sprintf('Не удалось создать категорию "%s"', $categoryName), Log::ERROR, 'com_estakadaimport');
                        return null;
                    }
                } else {
                    // Обычный продавец не может создавать категории
                    // Log::add(sprintf('Категория "%s" не найдена и пользователь не имеет прав для создания (родитель: %d)', $categoryName, $currentCategoryId), Log::WARNING, 'com_estakadaimport');
                    return null;
                }
            }
            
            $currentCategoryId = $categoryId;
            // Log::add(sprintf('Найдена/создана категория ID %d', $categoryId), Log::DEBUG, 'com_estakadaimport');
        }
        
        // Log::add(sprintf('Финальный ID категории: %d', $currentCategoryId), Log::DEBUG, 'com_estakadaimport');
        return $currentCategoryId;
    }

    /**
     * Создание новой категории
     */
    protected function createCategory($categoryName, $parentId = 0)
    {
        $user = Factory::getUser();
        $db = $this->db;
        
        try {
            // Создаем основную запись категории
            $category = new \stdClass();
            $category->virtuemart_vendor_id = 1; // SuperUser vendor
            $category->category_parent_id = $parentId;
            $category->published = 1;
            $category->created_on = date('Y-m-d H:i:s');
            $category->created_by = $user->id;
            $category->modified_on = date('Y-m-d H:i:s');
            $category->modified_by = $user->id;
            
            $db->insertObject('#__virtuemart_categories', $category);
            $categoryId = $db->insertid();
            
            if (!$categoryId) {
                throw new \Exception('Не удалось создать категорию');
            }
            
            // Создаем языковую запись
            $categoryLang = new \stdClass();
            $categoryLang->virtuemart_category_id = $categoryId;
            $categoryLang->category_name = $categoryName;
            $categoryLang->slug = $this->generateCategorySlug($categoryName, $parentId); // Передаем parentId
            $categoryLang->category_description = '';
            $categoryLang->metadesc = '';
            $categoryLang->metakey = '';
            
            $db->insertObject('#__virtuemart_categories_ru_ru', $categoryLang);
            
            // Обновляем связь родитель-потомок если это подкатегория
            if ($parentId > 0) {
                $this->updateCategoryParentRelation($parentId, $categoryId);
                $this->updateHasChildrenFlag($parentId, true);
            }
            
            return $categoryId;
            
        } catch (\Exception $e) {
            Log::add(sprintf('Ошибка создания категории "%s": %s', $categoryName, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
            return null;
        }
    }

    /**
     * Генерация уникального slug для категории с правильной транслитерацией
     */
    protected function generateCategorySlug($categoryName, $parentId = 0)
    {
        // Транслитерация русского текста в латиницу
        $slug = $this->transliterate($categoryName);
        
        // Очистка slug от лишних символов
        $slug = preg_replace('/[^a-zA-Z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = strtolower($slug);
        
        // Если после транслитерации slug пустой, используем fallback
        if (empty($slug)) {
            $slug = 'category-' . uniqid();
            return $slug;
        }
        
        // Делаем slug уникальным (глобально)
        $baseSlug = $slug;
        $counter = 1;
        
        // Проверяем уникальность slug глобально
        while ($this->isSlugExists($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
            
            // Защита от бесконечного цикла
            if ($counter > 100) {
                $slug = $baseSlug . '-' . uniqid();
                break;
            }
        }
        
        return $slug;
    }

    /**
     * Проверка существования slug для категории (глобальная уникальность)
     */
    protected function isSlugExists($slug, $parentId = 0)
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__virtuemart_categories_ru_ru AS cl')
            ->where('cl.slug = ' . $this->db->quote($slug));
        
        return $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Транслитерация русского текста в латиницу
     */
    protected function transliterate($text)
    {
        $translitTable = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'j', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
            'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'Yo',
            'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
            'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
            'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '',
            'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'
        ];
        
        // Заменяем русские буквы
        $text = strtr($text, $translitTable);
        
        // Заменяем оставшиеся не-ASCII символы
        $text = preg_replace('/[^\x20-\x7E]/u', '', $text);
        
        return $text;
    }

    /**
     * Исправление некорректных slug у существующих категорий
     */
    protected function fixInvalidSlugs()
    {
        // Находим категории с некорректными slug (пустые, только цифры, с дефисами)
        $query = $this->db->getQuery(true)
            ->select('cl.virtuemart_category_id, cl.category_name, cl.slug, c.category_parent_id')
            ->from('#__virtuemart_categories_ru_ru AS cl')
            ->join('INNER', '#__virtuemart_categories AS c ON cl.virtuemart_category_id = c.virtuemart_category_id')
            ->where('(cl.slug = "" OR cl.slug IS NULL OR cl.slug REGEXP "^[-0-9]+$")');
        
        $invalidCategories = $this->db->setQuery($query)->loadObjectList();
        
        foreach ($invalidCategories as $category) {
            $newSlug = $this->generateCategorySlug($category->category_name);
            
            $updateQuery = $this->db->getQuery(true)
                ->update('#__virtuemart_categories_ru_ru')
                ->set('slug = ' . $this->db->quote($newSlug))
                ->where('virtuemart_category_id = ' . (int)$category->virtuemart_category_id);
            
            $this->db->setQuery($updateQuery)->execute();
            
            Log::add(sprintf('Исправлен slug категории "%s": %s -> %s', 
                $category->category_name, $category->slug, $newSlug), Log::INFO, 'com_estakadaimport');
        }
        
        // Дополнительно: исправляем дубликаты slug
        $this->fixDuplicateSlugs();
    }

    /**
     * Исправление дубликатов slug
     */
    protected function fixDuplicateSlugs()
    {
        // Находим дубликаты slug
        $query = $this->db->getQuery(true)
            ->select('cl.virtuemart_category_id, cl.category_name, cl.slug, COUNT(*) as duplicate_count')
            ->from('#__virtuemart_categories_ru_ru AS cl')
            ->group('cl.slug')
            ->having('duplicate_count > 1');
        
        $duplicates = $this->db->setQuery($query)->loadObjectList();
        
        foreach ($duplicates as $duplicate) {
            if ($duplicate->slug) {
                // Для каждого дубликата (кроме первого) генерируем новый slug
                $subQuery = $this->db->getQuery(true)
                    ->select('cl.virtuemart_category_id, cl.category_name')
                    ->from('#__virtuemart_categories_ru_ru AS cl')
                    ->where('cl.slug = ' . $this->db->quote($duplicate->slug))
                    ->order('cl.virtuemart_category_id ASC');
                
                $duplicateCategories = $this->db->setQuery($subQuery)->loadObjectList();
                
                // Оставляем первый slug без изменений, остальные исправляем
                $first = true;
                foreach ($duplicateCategories as $category) {
                    if (!$first) {
                        $newSlug = $this->generateCategorySlug($category->category_name);
                        
                        $updateQuery = $this->db->getQuery(true)
                            ->update('#__virtuemart_categories_ru_ru')
                            ->set('slug = ' . $this->db->quote($newSlug))
                            ->where('virtuemart_category_id = ' . (int)$category->virtuemart_category_id);
                        
                        $this->db->setQuery($updateQuery)->execute();
                        
                        Log::add(sprintf('Исправлен дубликат slug категории "%s": %s -> %s', 
                            $category->category_name, $duplicate->slug, $newSlug), Log::INFO, 'com_estakadaimport');
                    }
                    $first = false;
                }
            }
        }
    }

    /**
     * Обновление связи родитель-потомок
     */
    protected function updateCategoryParentRelation($parentId, $childId)
    {
        $relation = new \stdClass();
        $relation->category_parent_id = $parentId;
        $relation->category_child_id = $childId;
        $relation->ordering = 0;
        
        try {
            $this->db->insertObject('#__virtuemart_category_categories', $relation);
        } catch (\Exception $e) {
            Log::add(sprintf('Ошибка создания связи категорий %d -> %d: %s', $parentId, $childId, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
        }
    }

    /**
     * Обновление флага has_children у родительской категории
     */
    protected function updateHasChildrenFlag($categoryId, $hasChildren)
    {
        $query = $this->db->getQuery(true)
            ->update('#__virtuemart_categories')
            ->set('has_children = ' . ($hasChildren ? 1 : 0))
            ->where('virtuemart_category_id = ' . (int)$categoryId);
        
        try {
            $this->db->setQuery($query)->execute();
        } catch (\Exception $e) {
            Log::add(sprintf('Ошибка обновления флага has_children для категории %d: %s', $categoryId, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
        }
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

    /**
     * Проверяет, является ли пользователь SuperUser
     */
    protected function isSuperUser($userId = null)
    {
        if ($userId === null) {
            $user = Factory::getUser();
            $userId = $user->id;
        }
        
        return Factory::getUser($userId)->authorise('core.admin');
    }
}