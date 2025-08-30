<?php
namespace Joomla\Component\Estakadaimport\Site\Model\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;

trait PriceTrait
{
    /**
     * Обновление цены товара
     */
    protected function updateProductPrice($productId, $priceData, $vendorId)
    {
        try {
            // Очищаем цены от лишних символов
            $basePrice = $this->cleanPrice($priceData['base'] ?? 0);
            $overridePrice = $this->cleanPrice($priceData['override'] ?? 0);
            
            // Log::add(sprintf('Начало обновления цены товара %d: базовая=%.2f, модификатор=%.2f', $productId, $basePrice, $overridePrice), Log::DEBUG, 'com_estakadaimport');
            
            // Если модификатор цены не указан или равен 0, игнорируем его
            $hasOverride = ($overridePrice > 0);
            
            // Получаем ID валюты RUB
            $currencyId = $this->getCurrencyId('RUB');
            
            if (!$currencyId) {
                $errorMsg = sprintf('Не найдена валюта RUB для товара %d', $productId);
                // Log::add($errorMsg, Log::ERROR, 'com_estakadaimport');
                throw new \Exception($errorMsg);
            }

            // Проверяем существование записи о цене (БЕЗ vendor_id)
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__virtuemart_product_prices')
                ->where('virtuemart_product_id = ' . (int)$productId);
                // Убираем проверку по vendor_id: ->where('virtuemart_vendor_id = ' . (int)$vendorId);
            
            $exists = $this->db->setQuery($query)->loadResult();

            $priceRecord = new \stdClass();
            $priceRecord->virtuemart_product_id = $productId;
            // Убираем vendor_id из записи, если его нет в таблице
            // $priceRecord->virtuemart_vendor_id = $vendorId;
            $priceRecord->product_price = $basePrice;
            $priceRecord->product_currency = $currencyId;
            $priceRecord->price_quantity_start = 1;
            $priceRecord->price_quantity_end = 0; // 0 означает неограниченно
            $priceRecord->created_on = date('Y-m-d H:i:s');
            $priceRecord->modified_on = date('Y-m-d H:i:s');

            // Обрабатываем модификатор цены
            if ($hasOverride) {
                $priceRecord->product_override_price = $overridePrice;
                $priceRecord->override = 1; // Активируем переопределение цены
                // Log::add(sprintf('Товар %d: установлена новая цена %.2f вместо базовой %.2f', $productId, $overridePrice, $basePrice), Log::INFO, 'com_estakadaimport');
            } else {
                $priceRecord->product_override_price = 0;
                $priceRecord->override = 0; // Деактивируем переопределение
                // Log::add(sprintf('Товар %d: установлена базовая цена %.2f', $productId, $basePrice), Log::INFO, 'com_estakadaimport');
            }

            if ($exists) {
                // Обновляем только по product_id
                $this->db->updateObject('#__virtuemart_product_prices', $priceRecord, 'virtuemart_product_id');
            } else {
                $this->db->insertObject('#__virtuemart_product_prices', $priceRecord);
            }

            // Log::add(sprintf('Цена товара %d успешно обновлена', $productId), Log::INFO, 'com_estakadaimport');
            return true;
            
        } catch (\Exception $e) {
            // Log::add(sprintf('Ошибка обновления цены товара %d: %s', $productId, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
            return false;
        }
    }

    /**
     * Очистка и преобразование цены
     */
    protected function cleanPrice($priceValue)
    {
        if (empty($priceValue) || $priceValue === '0') {
            // Log::add(sprintf('Цена пустая или нулевая: %s', $priceValue), Log::DEBUG, 'com_estakadaimport');
            return 0;
        }

        // Log::add(sprintf('Очистка цены: исходное значение=%s', $priceValue), Log::DEBUG, 'com_estakadaimport');
        
        // Удаляем все нечисловые символы кроме точки и запятой
        $cleaned = preg_replace('/[^\d,\.]/', '', (string)$priceValue);
        
        // Заменяем запятую на точку
        $cleaned = str_replace(',', '.', $cleaned);
        
        // Удаляем лишние точки (оставляем только первую)
        if (substr_count($cleaned, '.') > 1) {
            $parts = explode('.', $cleaned);
            $cleaned = $parts[0] . '.' . implode('', array_slice($parts, 1));
        }

        $result = (float)$cleaned;
        // Log::add(sprintf('Очистка цены: результат=%.2f', $result), Log::DEBUG, 'com_estakadaimport');
        
        return $result;
    }

    /**
     * Получение ID валюты по коду
     */
    protected function getCurrencyId($currencyCode)
    {
        $query = $this->db->getQuery(true)
            ->select('virtuemart_currency_id')
            ->from('#__virtuemart_currencies')
            ->where('currency_code_3 = ' . $this->db->quote($currencyCode));
        
        return $this->db->setQuery($query)->loadResult();
    }

    /**
     * Парсинг данных цены из строки Excel
     */
    protected function parsePriceData($row, $columnMap)
    {
        $basePrice = $this->decodeUnicodeString($row[$columnMap['product_price']] ?? 0);
        $overridePrice = $this->decodeUnicodeString($row[$columnMap['price_override']] ?? 0);
        
        return [
            'base' => $basePrice,
            'override' => $overridePrice
        ];
    }
}