<?php
namespace Joomla\Component\Estakadaimport\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportModel extends BaseModel
{
    protected $logCategory = 'com_estakadaimport.import';
    protected $vendorId;
    protected $userId;

    public function importProducts($filePath, $categoryId)
    {
        $this->initLogging();
        $this->validateUser();

        try {
            $spreadsheet = $this->loadSpreadsheet($filePath);
            $data = $this->parseSpreadsheet($spreadsheet);
            
            return $this->processImport($data, $categoryId);
            
        } catch (\Exception $e) {
            $this->logError($e->getMessage(), 'CRITICAL');
            throw $e;
        }
    }

    protected function initLogging()
    {
        Log::addLogger([
            'text_file' => 'com_estakadaimport_import.php',
            'text_entry_format' => '{DATETIME} {PRIORITY} {MESSAGE}'
        ], Log::ALL, [$this->logCategory]);
    }

    protected function validateUser()
    {
        $this->userId = Factory::getUser()->id;
        
        if ($this->userId == 0) {
            throw new \RuntimeException('Доступ запрещен. Необходима авторизация.');
        }

        $this->vendorId = $this->getVendorId();
        
        if (!$this->vendorId) {
            throw new \RuntimeException('Пользователь не является продавцом.');
        }
    }

    protected function getVendorId()
    {
        $db = $this->getDbo();
        $query = $db->getQuery(true)
            ->select('virtuemart_vendor_id')
            ->from('#__virtuemart_vmusers')
            ->where('virtuemart_user_id = ' . (int)$this->userId);
            
        return $db->setQuery($query)->loadResult();
    }

    protected function loadSpreadsheet($filePath)
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException('Файл не найден: ' . $filePath);
        }

        try {
            return IOFactory::load($filePath);
        } catch (\Exception $e) {
            throw new \RuntimeException('Ошибка чтения файла: ' . $e->getMessage());
        }
    }

    protected function parseSpreadsheet($spreadsheet)
    {
        $sheet = $spreadsheet->getActiveSheet();
        $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, true, false)[0];
        $rows = $sheet->toArray();
        
        if (count($rows) <= 1) {
            throw new \RuntimeException('Файл не содержит данных для импорта');
        }

        $columnMap = $this->createColumnMap($headers);
        array_shift($rows); // Удаляем заголовки

        return [
            'columns' => $columnMap,
            'rows' => $rows
        ];
    }

    protected function createColumnMap($headers)
    {
        $map = [];
        $required = ['product_sku' => 'Артикул'];
        $optional = [
            'product_name' => ['Наименование товара', 'Название'],
            'product_in_stock' => ['Количество на складе', 'Остаток']
        ];

        foreach ($headers as $index => $header) {
            $header = trim($header);
            
            // Проверяем обязательные поля
            if ($header === $required['product_sku']) {
                $map['product_sku'] = $index;
                continue;
            }
            
            // Проверяем опциональные поля
            foreach ($optional as $key => $variants) {
                if (in_array($header, $variants)) {
                    $map[$key] = $index;
                    break;
                }
            }
        }

        if (!isset($map['product_sku'])) {
            throw new \RuntimeException('Не найден обязательный столбец "Артикул"');
        }

        return $map;
    }

    protected function processImport($data, $categoryId)
    {
        $results = [
            'success' => 0,
            'errors' => 0,
            'skipped' => 0
        ];

        foreach ($data['rows'] as $rowIndex => $row) {
            try {
                $productData = $this->prepareProductData($row, $data['columns']);
                
                if ($this->skipProduct($productData)) {
                    $results['skipped']++;
                    continue;
                }

                $productId = $this->saveProduct($productData, $categoryId);
                
                if ($productId) {
                    $results['success']++;
                    $this->logInfo(sprintf(
                        'Товар %s обработан (ID: %d)', 
                        $productData['sku'], 
                        $productId
                    ));
                }

            } catch (\Exception $e) {
                $results['errors']++;
                $this->logError(sprintf(
                    'Ошибка в строке %d: %s', 
                    $rowIndex + 2, 
                    $e->getMessage()
                ));
            }
        }

        return $results;
    }

    protected function prepareProductData($row, $columnMap)
    {
        return [
            'sku' => trim($row[$columnMap['product_sku']] ?? ''),
            'name' => trim($row[$columnMap['product_name'] ?? ''),
            'stock' => (int)($row[$columnMap['product_in_stock'] ?? 0)
        ];
    }

    protected function skipProduct($productData)
    {
        return empty($productData['sku']) || empty($productData['name']);
    }

    protected function saveProduct($productData, $categoryId)
    {
        $db = $this->getDbo();
        $date = Factory::getDate()->toSql();
        
        // Основные данные товара
        $product = new \stdClass();
        $product->virtuemart_vendor_id = $this->vendorId;
        $product->product_sku = $productData['sku'];
        $product->product_in_stock = $productData['stock'];
        $product->created_by = $this->userId;
        $product->modified_by = $this->userId;
        $product->created_on = $date;
        $product->modified_on = $date;
        $product->published = 1;

        $productId = $this->getProductIdBySku($productData['sku']);
        
        if ($productId) {
            $product->virtuemart_product_id = $productId;
            $db->updateObject('#__virtuemart_products', $product, 'virtuemart_product_id');
        } else {
            $db->insertObject('#__virtuemart_products', $product);
            $productId = $db->insertid();
            
            // Связь с категорией
            $this->linkProductToCategory($productId, $categoryId);
        }

        // Языковые данные
        $this->saveProductLang(
            $productId,
            $productData['name'],
            $this->generateSlug($productData['name'], $productData['sku'])
        );

        return $productId;
    }

    protected function getProductIdBySku($sku)
    {
        $db = $this->getDbo();
        $query = $db->getQuery(true)
            ->select('virtuemart_product_id')
            ->from('#__virtuemart_products')
            ->where('product_sku = ' . $db->quote($sku));
            
        return $db->setQuery($query)->loadResult();
    }

    protected function linkProductToCategory($productId, $categoryId)
    {
        $db = $this->getDbo();
        $relation = new \stdClass();
        $relation->virtuemart_product_id = $productId;
        $relation->virtuemart_category_id = $categoryId;
        
        $db->insertObject('#__virtuemart_product_categories', $relation);
    }

    protected function saveProductLang($productId, $name, $slug)
    {
        $db = $this->getDbo();
        $lang = new \stdClass();
        $lang->virtuemart_product_id = $productId;
        $lang->product_name = $name;
        $lang->slug = $slug;
        $lang->product_desc = '';
        $lang->metadesc = '';
        
        // Проверяем существование записи
        $query = $db->getQuery(true)
            ->select('virtuemart_product_id')
            ->from('#__virtuemart_products_ru_ru')
            ->where('virtuemart_product_id = ' . (int)$productId);
            
        if ($db->setQuery($query)->loadResult()) {
            $db->updateObject('#__virtuemart_products_ru_ru', $lang, 'virtuemart_product_id');
        } else {
            $db->insertObject('#__virtuemart_products_ru_ru', $lang);
        }
    }

    protected function generateSlug($name, $sku)
    {
        $slug = JApplicationHelper::stringURLSafe($name);
        return $slug ?: JApplicationHelper::stringURLSafe($sku);
    }

    protected function logInfo($message)
    {
        Log::add($message, Log::INFO, $this->logCategory);
    }

    protected function logError($message, $level = 'ERROR')
    {
        Log::add($message, constant('JLog::' . $level), $this->logCategory);
    }
}