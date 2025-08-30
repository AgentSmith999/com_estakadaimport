<?php
namespace Joomla\Component\Estakadaimport\Site\Model\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log; // Добавьте эту строку

trait CustomTrait
{
    /**
     * Получает кастомные поля для профиля с информацией о плагине
     */
    protected function getCustomFields($profileId): array
    {
        $query = $this->db->getQuery(true)
            ->select(['c.virtuemart_custom_id', 'c.custom_title', 'c.field_type', 'c.custom_element'])
            ->from('#__virtuemart_customs AS c')
            ->where('c.custom_parent_id = ' . (int)$profileId);
            
        $result = $this->db->setQuery($query)->loadAssocList();

        // ДЕБАГ: логируем полученные поля
        // Log::add(sprintf('Получены поля профиля %d: %s', $profileId, print_r($result, true)), Log::DEBUG, 'com_estakadaimport');
        
        // Преобразуем в простой массив
        $fields = [];
        foreach ($result as $row) {
            $fields[] = [
                'virtuemart_custom_id' => $row['virtuemart_custom_id'],
                'custom_title' => $row['custom_title'],
                'field_type' => $row['field_type'],
                'custom_element' => $row['custom_element'] ?? null
            ];
        }
        
        return $fields;
    }

    /**
     * Получает универсальные кастомные поля с информацией о плагине
     */
    protected function getUniversalFields(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['virtuemart_custom_id', 'custom_title', 'field_type', 'custom_element'])
            ->from('#__virtuemart_customs')
            ->where('custom_parent_id = 0')
            ->where('field_type IN (' . $this->db->quote('S') . ',' . $this->db->quote('E') . ')');
            
        $result = $this->db->setQuery($query)->loadAssocList();

        // ДЕБАГ: логируем полученные универсальные поля
        // Log::add(sprintf('Получены универсальные поля: %s', print_r($result, true)), Log::DEBUG, 'com_estakadaimport');
        
        // Преобразуем в простой массив
        $fields = [];
        foreach ($result as $row) {
            $fields[] = [
                'virtuemart_custom_id' => $row['virtuemart_custom_id'],
                'custom_title' => $row['custom_title'],
                'field_type' => $row['field_type'],
                'custom_element' => $row['custom_element'] ?? null
            ];
        }
        
        return $fields;
    }

    /**
     * Получает универсальные кастомные поля с информацией о плагине
     */
    protected function savePluginFieldValue($productId, $fieldId, $value, $fieldElement = null)
    {
        // Если fieldElement пустой, но поле типа 'E', проверяем в базе данных
        if (empty($fieldElement)) {
            $query = $this->db->getQuery(true)
                ->select('custom_element')
                ->from('#__virtuemart_customs')
                ->where('virtuemart_custom_id = ' . (int)$fieldId);
            
            $fieldElement = $this->db->setQuery($query)->loadResult();
        }
        
        // Log::add(sprintf('Обработка плагинного поля %d для товара %d: %s (элемент: %s)', $fieldId, $productId, $value, $fieldElement), Log::DEBUG, 'com_estakadaimport');
        
        // 1. Сохраняем значение в основную таблицу и получаем ID записи
        $customFieldId = $this->saveSingleCustomFieldValue($productId, $fieldId, $value);

        // 2. Для specific plugins добавляем дополнительную логику
        if ($fieldElement === 'customfieldsforall') { 
            // Log::add(sprintf('Связывание: productId=%d, valueId=%d, customFieldId=%d', $productId, $customsforallValueId, $customFieldId), Log::DEBUG, 'com_estakadaimport');

            $this->handleCustomForAllPlugin($productId, $fieldId, $value, $customFieldId);
        }
        // Здесь можно добавить обработку других плагинов
    }

    /**
     * Обработка кастомных полей с поддержкой плагинных полей
     */
    protected function processCustomFields($productId, $row, $columnMap, $profileId)
    {
        // Получаем поля профиля
        $profileFields = $this->getCustomFields($profileId);
        
        // Получаем универсальные поля
        $universalFields = $this->getUniversalFields();
        
        // Объединяем все поля для обработки
        $allFields = array_merge($profileFields, $universalFields);
        
        // Log::add(sprintf('Обработка кастомных полей для товара %d. Всего полей: %d', $productId, count($allFields)), Log::DEBUG, 'com_estakadaimport');
        
        foreach ($allFields as $field) {
            $fieldId = $field['virtuemart_custom_id'];
            $fieldTitle = $field['custom_title'];
            $fieldType = $field['field_type'];
            $fieldElement = $field['custom_element'] ?? null;
            
            // ДЕБАГ: логируем информацию о поле
            // Log::add(sprintf('Поле "%s" (ID: %d) - тип: %s, элемент: %s', $fieldTitle, $fieldId, $fieldType, var_export($fieldElement, true)), Log::DEBUG, 'com_estakadaimport');
            
            // Проверяем, есть ли такое поле в Excel
            if (isset($columnMap[$fieldTitle])) {
                $columnIndex = $columnMap[$fieldTitle];
                $value = $row[$columnIndex] ?? '';
                
                if (!empty($value)) {
                    // Log::add(sprintf('Обработка поля "%s" для товара %d: %s', $fieldTitle, $productId, $value), Log::DEBUG, 'com_estakadaimport');
                    
                    $this->saveCustomFieldValue($productId, $fieldId, $value, $fieldType, $fieldElement);
                } else {
                    // Log::add(sprintf('Пустое значение для поля "%s" товара %d', $fieldTitle, $productId), Log::DEBUG, 'com_estakadaimport');
                }
            } else {
                // Log::add(sprintf('Поле "%s" не найдено в Excel для товара %d', $fieldTitle, $productId), Log::DEBUG, 'com_estakadaimport');
            }
        }
    }

    /**
     * Сохраняет значение кастомного поля с поддержкой мульти-значений
     */
    protected function saveCustomFieldValue($productId, $fieldId, $value, $fieldType, $fieldElement = null)
    {
        // Для плагинных полей с типом E (customfieldsforall и другие плагины)
        $isPluginField = ($fieldType === 'E');
        
        if ($isPluginField && !empty($value)) {
            // Обрабатываем мульти-значения с поддержкой multiple разделителей
            $values = $this->splitMultiValueString($value);
            
            // Log::add(sprintf('Мульти-значения для плагинного поля %d товара %d: %s', $fieldId, $productId, print_r($values, true)), Log::DEBUG, 'com_estakadaimport');
            
            if (empty($values)) {
                // Log::add(sprintf('Пустые значения после разделения для поля %d товара %d', $fieldId, $productId), Log::DEBUG, 'com_estakadaimport');
                return;
            }
            
            // Сохраняем каждое значение отдельно
            foreach ($values as $singleValue) {
                if (!empty($singleValue)) {
                    $this->savePluginFieldValue($productId, $fieldId, $singleValue, $fieldElement);
                }
            }
        } else {
            // Обычное поле (одиночное значение)
            $this->saveSingleCustomFieldValue($productId, $fieldId, $value);
        }
    }

    /**
     * Разделяет строку значений с поддержкой multiple разделителей
     */
    protected function splitMultiValueString($value): array
    {
        if (empty($value)) {
            return [];
        }
        
        // Поддерживаемые разделители: | , ; 
        $value = str_replace([', ', '; ', ';'], '|', $value);
        $values = explode('|', $value);
        
        // Очищаем значения
        $values = array_map('trim', $values);
        $values = array_filter($values); // Убираем пустые
        $values = array_unique($values); // Убираем дубликаты
        
        return $values;
    }

    /**
     * Сохраняет одиночное значение кастомного поля и возвращает ID записи
     */
    protected function saveSingleCustomFieldValue($productId, $fieldId, $value)
    {
        // Проверяем существование записи
        $query = $this->db->getQuery(true)
            ->select('virtuemart_customfield_id')
            ->from('#__virtuemart_product_customfields')
            ->where('virtuemart_product_id = ' . (int)$productId)
            ->where('virtuemart_custom_id = ' . (int)$fieldId)
            ->where('customfield_value = ' . $this->db->quote($value));
        
        $existingId = $this->db->setQuery($query)->loadResult();
        
        if ($existingId) {
            // Запись уже существует, возвращаем ID
            // Log::add(sprintf('Значение уже существует для поля %d товара %d: %s (ID: %d)', $fieldId, $productId, $value, $existingId), Log::DEBUG, 'com_estakadaimport');
            return $existingId;
        }
        
        // Создаем новую запись
        $customField = new \stdClass();
        $customField->virtuemart_product_id = $productId;
        $customField->virtuemart_custom_id = $fieldId;
        $customField->customfield_value = $value;
        $customField->published = 1;
        
        $this->db->insertObject('#__virtuemart_product_customfields', $customField);
        $customFieldId = $this->db->insertid();
        
        // Log::add(sprintf('Сохранено поле %d для товара %d: %s (ID: %d)', $fieldId, $productId, $value, $customFieldId), Log::DEBUG, 'com_estakadaimport');
        
        return $customFieldId;
    }



    /**
     * Обрабатывает плагин customfieldsforall
     */
    protected function handleCustomForAllPlugin($productId, $fieldId, $value, $customFieldId)
    {
        // Log::add(sprintf('Обработка customfieldsforall для поля %d товара %d: %s (customFieldId: %d)', $fieldId, $productId, $value, $customFieldId), Log::DEBUG, 'com_estakadaimport');
        
        // Сохраняем значение в таблицу плагина (только если уникальное)
        $customsforallValueId = $this->saveToCustomForAllValuesTable($fieldId, $value);
        
        // Log::add(sprintf('Результат saveToCustomForAllValuesTable: %s', var_export($customsforallValueId, true)), Log::DEBUG, 'com_estakadaimport');
        
        // Связываем значение плагина с товаром
        if ($customsforallValueId && $customFieldId) {
            // Log::add(sprintf('Связывание: productId=%d, valueId=%d, customFieldId=%d', $productId, $customsforallValueId, $customFieldId), Log::DEBUG, 'com_estakadaimport');
            
            $this->linkProductToCustomForAllValue($productId, $customsforallValueId, $customFieldId);
        } else {
            // Log::add(sprintf('Не удалось получить IDs для связывания: valueId=%s, customFieldId=%d', var_export($customsforallValueId, true), $customFieldId), Log::WARNING, 'com_estakadaimport');
        }
    }

    /**
     * Сохраняет значение для плагинного поля customfieldsforall
     */
    protected function saveCustomForAllFieldValue($productId, $fieldId, $value)
    {
        // 1. Сохраняем значение в основную таблицу и получаем ID записи
        $customFieldId = $this->saveSingleCustomFieldValue($productId, $fieldId, $value);
        
        // 2. Сохраняем значение в таблицу плагина (только если уникальное)
        $customsforallValueId = $this->saveToCustomForAllValuesTable($fieldId, $value);
        
        // 3. Связываем значение плагина с товаром
        if ($customsforallValueId && $customFieldId) {
            $this->linkProductToCustomForAllValue($productId, $customsforallValueId, $customFieldId);
        }
    }

    /**
     * Сохраняет уникальные значения в таблицу плагина customfieldsforall и возвращает ID
     */
    protected function saveToCustomForAllValuesTable($fieldId, $value)
    {
        // Log::add(sprintf('Поиск значения в customsforall: fieldId=%d, value=%s', $fieldId, $value), Log::DEBUG, 'com_estakadaimport');
        
        // Проверяем, существует ли уже такое значение для этого поля
        $query = $this->db->getQuery(true)
            ->select('customsforall_value_id')
            ->from('#__virtuemart_custom_plg_customsforall_values')
            ->where('virtuemart_custom_id = ' . (int)$fieldId)
            ->where('customsforall_value_name = ' . $this->db->quote($value));
        
        $sql = (string)$query;
        // Log::add(sprintf('SQL запрос: %s', $sql), Log::DEBUG, 'com_estakadaimport');
        
        $existingId = $this->db->setQuery($query)->loadResult();
        
        if ($existingId) {
            // Значение уже существует
            // Log::add(sprintf('Значение плагина уже существует для поля %d: %s (ID: %d)', $fieldId, $value, $existingId), Log::DEBUG, 'com_estakadaimport');
            return $existingId;
        }
        
        // Log::add(sprintf('Создание нового значения плагина: fieldId=%d, value=%s', $fieldId, $value), Log::DEBUG, 'com_estakadaimport');
        
        // Создаем новую запись
        $pluginValue = new \stdClass();
        $pluginValue->virtuemart_custom_id = $fieldId;
        $pluginValue->customsforall_value_name = $value;
        $pluginValue->customsforall_value_label = $value;
        $pluginValue->parent_id = 0;
        $pluginValue->ordering = 0;
        $pluginValue->published = 1;
        
        try {
            // Log::add(sprintf('Вставляем объект: %s', print_r($pluginValue, true)), Log::DEBUG, 'com_estakadaimport');
            
            $result = $this->db->insertObject('#__virtuemart_custom_plg_customsforall_values', $pluginValue);
            $pluginValueId = $this->db->insertid();
            
            // Log::add(sprintf('Результат вставки: %s, новый ID: %d', var_export($result, true), $pluginValueId), Log::DEBUG, 'com_estakadaimport');
            
            if ($pluginValueId) {
                // Log::add(sprintf('Добавлено новое значение плагина для поля %d: %s (ID: %d)', $fieldId, $value, $pluginValueId), Log::INFO, 'com_estakadaimport');
                return $pluginValueId;
            } else {
                // Log::add(sprintf('Не удалось получить ID после вставки для поля %d: %s', $fieldId, $value), Log::ERROR, 'com_estakadaimport');
                return null;
            }
            
        } catch (\Exception $e) {
            Log::add(sprintf('Ошибка добавления значения плагина для поля %d: %s - %s', $fieldId, $value, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
            Log::add(sprintf('Trace: %s', $e->getTraceAsString()), Log::DEBUG, 'com_estakadaimport');
            return null;
        }
    }

    /**
     * Связывает товар со значением плагина customfieldsforall
     */
    protected function linkProductToCustomForAllValue($productId, $customsforallValueId, $customFieldId)
    {
        // Log::add(sprintf('Проверка связи: productId=%d, valueId=%d, customFieldId=%d', $productId, $customsforallValueId, $customFieldId), Log::DEBUG, 'com_estakadaimport');
        
        // Проверяем, существует ли уже связь
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__virtuemart_product_custom_plg_customsforall')
            ->where('virtuemart_product_id = ' . (int)$productId)
            ->where('customsforall_value_id = ' . (int)$customsforallValueId)
            ->where('customfield_id = ' . (int)$customFieldId);
        
        $sql = (string)$query;
        // Log::add(sprintf('SQL проверки связи: %s', $sql), Log::DEBUG, 'com_estakadaimport');
        
        $exists = $this->db->setQuery($query)->loadResult();
        
        if ($exists) {
            // Log::add(sprintf('Связь уже существует: товар %d -> значение %d -> поле %d', $productId, $customsforallValueId, $customFieldId), Log::DEBUG, 'com_estakadaimport');
            return;
        }
        
        // Log::add(sprintf('Создание новой связи: productId=%d, valueId=%d, customFieldId=%d', $productId, $customsforallValueId, $customFieldId), Log::DEBUG, 'com_estakadaimport');
        
        // Создаем новую связь
        $link = new \stdClass();
        $link->virtuemart_product_id = $productId;
        $link->customsforall_value_id = $customsforallValueId;
        $link->customfield_id = $customFieldId;
        
        try {
            // Log::add(sprintf('Вставляем связь: %s', print_r($link, true)), Log::DEBUG, 'com_estakadaimport');
            
            $result = $this->db->insertObject('#__virtuemart_product_custom_plg_customsforall', $link);
            $linkId = $this->db->insertid();
            
            // Log::add(sprintf('Результат вставки связи: %s, ID связи: %d', var_export($result, true), $linkId), Log::DEBUG, 'com_estakadaimport');
            
            if ($linkId) {
                // Log::add(sprintf('Создана связь: товар %d -> значение %d -> поле %d (ID связи: %d)', $productId, $customsforallValueId, $customFieldId, $linkId), Log::INFO, 'com_estakadaimport');
            } else {
                // Log::add(sprintf('Не удалось получить ID связи для товар %d -> значение %d -> поле %d', $productId, $customsforallValueId, $customFieldId), Log::ERROR, 'com_estakadaimport');
            }
            
        } catch (\Exception $e) {
            Log::add(sprintf('Ошибка создания связи товар %d -> значение %d -> поле %d: %s', $productId, $customsforallValueId, $customFieldId, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
            Log::add(sprintf('Trace: %s', $e->getTraceAsString()), Log::DEBUG, 'com_estakadaimport');
        }
    }

}