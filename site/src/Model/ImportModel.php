<?php
namespace Joomla\Component\Estakadaimport\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Application\ApplicationHelper;

// Подключаем автозагрузчик Composer
require_once JPATH_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Подключаем трейты
require_once __DIR__ . '/Traits/CategoryTrait.php';
require_once __DIR__ . '/Traits/ManufacturerTrait.php';
require_once __DIR__ . '/Traits/ImageTrait.php';
require_once __DIR__ . '/Traits/ProgressTrait.php';
require_once __DIR__ . '/Traits/PriceTrait.php';
require_once __DIR__ . '/Traits/CustomTrait.php';
require_once __DIR__ . '/Traits/UniqueBrandTrait.php';

class ImportModel extends BaseModel
{
    // Используем трейты ВНУТРИ класса
    use \Joomla\Component\Estakadaimport\Site\Model\Traits\CategoryTrait,
        \Joomla\Component\Estakadaimport\Site\Model\Traits\ManufacturerTrait,
        \Joomla\Component\Estakadaimport\Site\Model\Traits\ImageTrait,
        \Joomla\Component\Estakadaimport\Site\Model\Traits\ProgressTrait,
        \Joomla\Component\Estakadaimport\Site\Model\Traits\PriceTrait,
        \Joomla\Component\Estakadaimport\Site\Model\Traits\CustomTrait,
        \Joomla\Component\Estakadaimport\Site\Model\Traits\UniqueBrandTrait;
    
    protected $db;
    protected $logOptions = [
        'text_file' => 'com_estakadaimport.log',
        'text_entry_format' => '{DATETIME} {PRIORITY} {MESSAGE}'
    ];

    protected $totalImages = 0;
    protected $currentImageIndex = 0;

    /**
     * Конструктор модели
     */
    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->db = Factory::getDbo();
        Log::addLogger($this->logOptions, Log::ALL, ['com_estakadaimport']);
    }

    /**
     * Основной метод импорта
     */
    public function importFromExcel($filePath, $profileId = 0, $selectedVendorId = null, $updatePricesOnly = false)
    {
        // ДОБАВИМ ДЕТАЛЬНОЕ ЛОГИРОВАНИЕ ПАРАМЕТРОВ
    Log::add(sprintf('НАЧАЛО ИМПОРТА: filePath=%s, profileId=%d, selectedVendorId=%s, updatePricesOnly=%d', $filePath, $profileId, $selectedVendorId, (int)$updatePricesOnly), Log::DEBUG, 'com_estakadaimport');
    // Добавляем параметр $updatePricesOnly
    Log::add(sprintf('Начало импорта (updatePricesOnly): selectedVendorId=%s, updatePricesOnly=%d', $selectedVendorId, $updatePricesOnly), Log::DEBUG, 'com_estakadaimport');

        $user = Factory::getUser();
        $app = Factory::getApplication();

        // Проверяем авторизацию
        if ($user->guest) {
            $message = 'Доступ запрещен. Необходима авторизация.';
            Log::add($message, Log::ERROR, 'com_estakadaimport');
            $app->enqueueMessage($message, 'error');
            return false;
        }

        // Получаем vendor_id с учетом выбора SuperUser
        $vendorId = $this->getVendorId($user->id, $selectedVendorId);
        if (empty($vendorId)) {
            $message = 'Пользователь не является продавцом.';
            Log::add($message, Log::ERROR, 'com_estakadaimport');
            $app->enqueueMessage($message, 'error');
            return false;
        }

        // Логируем информацию о том, кто импортирует
        if ($selectedVendorId && $this->isSuperUser($user->id)) {
            Log::add(sprintf('SuperUser импортирует от имени продавца ID: %d', $vendorId), Log::INFO, 'com_estakadaimport');
        }

        try {
            Log::add('Начало импорта для vendor_id: ' . $vendorId, Log::INFO, 'com_estakadaimport');

            // Если режим "только обновить цены", пропускаем некоторые шаги
            if (!$updatePricesOnly) {
                // Исправляем некорректные slug перед импортом
                $this->fixInvalidSlugs();
            }

            // ДЕБАГ: логируем полученный profileId
            Log::add('Profile ID received in importFromExcel: ' . $profileId, Log::DEBUG, 'com_estakadaimport');
            
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            
            // Получаем заголовки
            $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', NULL, TRUE, FALSE)[0];
            $columnMap = $this->createColumnMap($headers);

            // В режиме "только обновить цены" пропускаем проверку профиля
            if (!$updatePricesOnly) {
                // Проверяем соответствие профиля ДО подсчета изображений
                Log::add(sprintf('Calling validateProfileMatch with profileId: %d', $profileId), Log::DEBUG, 'com_estakadaimport');
                $this->validateProfileMatch($profileId, $columnMap);
                Log::add('validateProfileMatch completed successfully', Log::DEBUG, 'com_estakadaimport');
            }
            
            // Получаем данные
            $rows = $sheet->toArray();
            if (count($rows) <= 1) {
                throw new \Exception("Файл не содержит данных для импорта");
            }
            array_shift($rows);

            // В режиме "только обновить цены" не считаем изображения
            if (!$updatePricesOnly) {
                // Подсчитываем общее количество изображений
                $this->totalImages = 0;
                $imageColumnIndex = $columnMap['product_image'] ?? null;
                
                if ($imageColumnIndex !== null) {
                    foreach ($rows as $row) {
                        $imageUrls = $row[$imageColumnIndex] ?? '';
                        if (!empty($imageUrls)) {
                            $urls = explode('|', $imageUrls);
                            $this->totalImages += count(array_filter($urls, function($url) {
                                return !empty(trim($url));
                            }));
                        }
                    }
                }
            } else {
                $this->totalImages = 0; // В режиме обновления цен изображения не обрабатываются
            }

            // В режиме "только обновить цены" пропускаем проверку профиля
            if (!$updatePricesOnly) {
                Log::add(sprintf('Calling validateProfileMatch with profileId: %d', $profileId), Log::DEBUG, 'com_estakadaimport');
                $this->validateProfileMatch($profileId, $columnMap);
                Log::add('validateProfileMatch completed successfully', Log::DEBUG, 'com_estakadaimport');
            }

            error_log('Total images to process: ' . $this->totalImages);
            
            $this->currentImageIndex = 0;
            
            // Устанавливаем начальный прогресс
            $this->setImportProgress(0, $this->totalImages, 'Подготовка к импорту...');

            error_log('Initial progress set');

            $count = 0;
            $errors = 0;
            
            foreach ($rows as $rowIndex => $row) {
                try {
                    error_log('Processing row ' . ($rowIndex + 2)); // +2 потому что первая строка - заголовки
                    
                    // В режиме "только обновить цены" обрабатываем только нужные колонки
                    if ($updatePricesOnly) {
                        $data = [
                            'Артикул' => $this->decodeUnicodeString($row[$columnMap['product_sku']] ?? ''),
                            'Цена' => $this->decodeUnicodeString($row[$columnMap['product_price']] ?? 0)
                        ];
                        
                        // Добавляем модификатор цены если есть
                        if (isset($columnMap['price_override'])) {
                            $data['Модификатор цены'] = $this->decodeUnicodeString($row[$columnMap['price_override']] ?? 0);
                        } else {
                            $data['Модификатор цены'] = 0;
                        }
                        
                        $priceData = [
                            'base' => $data['Цена'],
                            'override' => $data['Модификатор цены']
                        ];
                    } else {
                        // Полный режим импорта
                        $decodedRow = array_map([$this, 'decodeUnicodeString'], $row);

                        $data = [
                            'Артикул' => $this->decodeUnicodeString($row[$columnMap['product_sku']] ?? ''),
                            'Наименование' => $this->decodeUnicodeString($row[$columnMap['product_name']] ?? ''),
                            'Количество' => $this->decodeUnicodeString($row[$columnMap['product_in_stock']] ?? 0),
                            'Изображение' => $this->decodeUnicodeString($row[$columnMap['product_image']] ?? ''),
                            'Категория (Номер/ID/Название)' => $this->decodeUnicodeString($row[$columnMap['categories']] ?? ''),
                            'Производитель' => $this->decodeUnicodeString($row[$columnMap['manufacturer']] ?? '')
                        ];

                        // Парсинг цен отдельно
                        $priceData = $this->parsePriceData($decodedRow, $columnMap);
                        $data['Цена'] = $priceData['base'];
                        $data['Модификатор цены'] = $priceData['override'];
                    }

                    // Логирование цен
                    Log::add(sprintf('Строка %d - данные цены: базовая=%s, модификатор=%s', $rowIndex + 2, $data['Цена'], $data['Модификатор цены']), Log::DEBUG, 'com_estakadaimport');

                    // Проверяем обязательные поля
                    if (empty($data['Артикул'])) {
                        throw new \Exception('Не указан артикул');
                    }
                    
                    // В полном режиме проверяем дополнительные обязательные поля
                    if (!$updatePricesOnly) {
                        if (empty($data['Наименование'])) {
                            throw new \Exception('Не указано наименование');
                        }
                        
                        if (empty($data['Категория (Номер/ID/Название)'])) {
                            throw new \Exception('Не указана категория');
                        }

                        if (empty($data['Производитель'])) {
                            throw new \Exception('Не указан производитель');
                        }
                    }

                    // Обработка товара с передачей profileId и флага updatePricesOnly
                    $productId = $this->processProduct($data, $vendorId, $user->id, $priceData, $profileId, $updatePricesOnly);
                    
                    if ($productId) {
                        // В полном режиме обрабатываем кастомные поля и изображения
                        if (!$updatePricesOnly) {
                            $this->processCustomFields($productId, $decodedRow, $columnMap, $profileId);

                            // Обработка изображений
                            if (!empty($data['Изображение'])) {
                                $this->processImages($data['Изображение'], $productId, $vendorId, $user->id, $updatePricesOnly);
                            }
                        }
                        
                        $count++;
                        Log::add(sprintf('Товар %s обработан (ID: %d)', $data['Артикул'], $productId), Log::INFO, 'com_estakadaimport');
                    }
                    
                } catch (\Exception $e) {
                    $this->clearImportProgress();
                    Log::add('Прогресс импорта очищен при ошибке', Log::DEBUG, 'com_estakadaimport');

                    // Сохраняем оригинальное сообщение об ошибке
                    $errorMsg = $e->getMessage();
                    
                    // Если это ошибка несоответствия профиля, возвращаем ее как есть
                    if (strpos($errorMsg, 'ERROR_PROFILE_MISMATCH') === 0) {
                        Log::add('Profile mismatch error detected: ' . $errorMsg, Log::ERROR, 'com_estakadaimport');
                        $app->enqueueMessage($errorMsg, 'error');
                        return false;
                    }
                    
                    // Для других ошибок добавляем общий префикс
                    $errorMsg = 'Ошибка импорта: ' . $errorMsg;
                    Log::add($errorMsg, Log::CRITICAL, 'com_estakadaimport');
                    $app->enqueueMessage($errorMsg, 'error');
                    return false;
                }
            }

            $message = sprintf('Импорт завершен. Успешно: %d, с ошибками: %d', $count, $errors);
            Log::add($message, ($errors ? Log::WARNING : Log::INFO), 'com_estakadaimport');
            $app->enqueueMessage($message, ($errors ? 'warning' : 'message'));
            
            $this->clearImportProgress();
            return $errors === 0;

        } catch (\Exception $e) {
            $this->clearImportProgress();
            Log::add('Прогресс импорта очищен при ошибке', Log::DEBUG, 'com_estakadaimport');

            $errorMsg = 'Ошибка импорта: ' . $e->getMessage();
            Log::add($errorMsg, Log::CRITICAL, 'com_estakadaimport');
            $app->enqueueMessage($errorMsg, 'error');
            return false;
        }
    }

    /**
     * Создание карты колонок
     */
    protected function createColumnMap($headers)
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $header = trim($this->decodeUnicodeString($header)); // Декодируем заголовок!
            Log::add(sprintf('Заголовок %d: %s', $index, $header), Log::DEBUG, 'com_estakadaimport');
            
            switch ($header) {
                case 'Артикул': $map['product_sku'] = $index; break;
                case 'Наименование товара': $map['product_name'] = $index; break;
                case 'Количество на складе': $map['product_in_stock'] = $index; break;
                case 'Изображение': $map['product_image'] = $index; break;
                case 'Категория (Номер/ID/Название)': $map['categories'] = $index; break;
                case 'Производитель': $map['manufacturer'] = $index; break;
                case 'Цена': $map['product_price'] = $index; break;
                case 'Модификатор цены': $map['price_override'] = $index; break;
                case 'Цена продажи': $map['product_price'] = $index; break;
                case 'Новая цена': $map['price_override'] = $index; break;
                case 'Стоимость': $map['product_price'] = $index; break;
                default:
                // Все остальные заголовки считаем кастомными полями
                $map[$header] = $index;
                break;
            }
        }
        
        // Логируем найденные колонки
        Log::add('Найденные колонки: ' . print_r($map, true), Log::DEBUG, 'com_estakadaimport');
        
        // Проверяем обязательные колонки
        if (!isset($map['product_sku'])) {
            throw new \Exception('Не найден обязательный столбец "Артикул"');
        }
        
        if (!isset($map['categories'])) {
            throw new \Exception('Не найден обязательный столбец "Категория (Номер/ID/Название)"');
        }
        
        // Производитель теперь обязательный
        if (!isset($map['manufacturer'])) {
            throw new \Exception('Не найден обязательный столбец "Производитель"');
        }
        
        return $map;
    }

    /**
     * Обработка товара
     */
    protected function processProduct($data, $vendorId, $userId, $priceData = null, $profileId = 0, $updatePricesOnly = false)
    {
        Log::add(sprintf('Обработка товара: vendorId=%d, updatePricesOnly=%d', $vendorId, $updatePricesOnly), Log::DEBUG, 'com_estakadaimport');

        // Обновляем прогресс - этап обработки товара
        $this->setImportProgress(
            $this->currentImageIndex, 
            $this->totalImages, 'Обработка товара: ' . ($data['Артикул'] ?? '')
        );

        // Проверяем обязательные поля
        if (empty($data['Артикул'])) {
            throw new \Exception('Не указан артикул');
        }
        
        // В полном режиме проверяем дополнительные обязательные поля
        if (!$updatePricesOnly) {
            if (empty($data['Наименование'])) {
                throw new \Exception('Не указано наименование');
            }
            
            if (empty($data['Категория (Номер/ID/Название)'])) {
                throw new \Exception('Не указана категория');
            }
            
            if (empty($data['Производитель'])) {
                throw new \Exception('Не указан производитель');
            }

            Log::add(sprintf('ПОЛНЫЙ РЕЖИМ: обрабатываем кастомные поля и изображения для товара ID: %d', $productId), Log::DEBUG, 'com_estakadaimport');
            $this->processCustomFields($productId, $decodedRow, $columnMap, $profileId);

            // Обработка изображений
            if (!empty($data['Изображение'])) {
                Log::add(sprintf('Обработка изображений для товара ID: %d', $productId), Log::DEBUG, 'com_estakadaimport');
                $this->processImages($data['Изображение'], $productId, $vendorId, $userId, $updatePricesOnly);
            }
        } else {
            Log::add(sprintf('РЕЖИМ ОБНОВЛЕНИЯ ЦЕН: пропускаем кастомные поля и изображения для товара ID: %d', $productId), Log::DEBUG, 'com_estakadaimport');
        }

        // Нормализуем артикул (добавляем префикс vendor_id если нужно)
        $originalSku = trim($data['Артикул']);
        $vendorSku = $this->normalizeSku($originalSku, $vendorId);
        
        // Логируем преобразование артикула
        if ($originalSku !== $vendorSku) {
            Log::add(sprintf('Артикул нормализован: %s -> %s', $originalSku, $vendorSku), Log::DEBUG, 'com_estakadaimport');
        }
        
        // Если priceData не передан, создаем из данных
        if ($priceData === null) {
            $priceData = [
                'base' => $data['Цена'] ?? 0,
                'override' => $data['Модификатор цены'] ?? 0
            ];
        }

        // Детальное логирование перед обновлением цены
        Log::add(sprintf('Товар %s: обновление цены - базовая: %.2f, модификатор: %.2f', $originalSku, $priceData['base'], $priceData['override']), Log::INFO, 'com_estakadaimport');

        $basePrice = $this->cleanPrice($priceData['base']);
        if ($basePrice <= 0) {
            Log::add(sprintf('Внимание: товар %s имеет нулевую или отрицательную базовую цену: %s', $originalSku, $data['Цена']), Log::WARNING, 'com_estakadaimport');
        }

        // Ищем товар по нормализованному артикулу
        $productId = $this->getProductIdBySku($originalSku, $vendorId);
        
        if (!$productId) {
            // Товар не найден
            if ($updatePricesOnly) {
                // В режиме "только обновить цены" пропускаем несуществующие товары
                Log::add(sprintf('Товар %s не найден - пропускаем обновление цены', $vendorSku), Log::WARNING, 'com_estakadaimport');
                return null;
            } else {
                // В полном режиме создаем новый товар
                return $this->createNewProduct($data, $vendorId, $userId, $priceData, $vendorSku, $originalSku);
            }
        }
        
        // Товар найден - обновляем
        return $this->updateExistingProduct($productId, $data, $vendorId, $userId, $priceData, $updatePricesOnly, $vendorSku);
    }

    /**
     * Создание нового товара (полный режим)
     */
    protected function createNewProduct($data, $vendorId, $userId, $priceData, $vendorSku, $originalSku)
    {
        Log::add(sprintf('Создание нового товара: %s', $vendorSku), Log::INFO, 'com_estakadaimport');

        // Декодируем значения на случай если они пришли в Unicode
        $categoryValue = $this->decodeUnicodeString($data['Категория (Номер/ID/Название)']);
        $manufacturerValue = $this->decodeUnicodeString($data['Производитель']);
        
        // Получаем/создаем производителя
        $manufacturerId = $this->getOrCreateManufacturer($data['Производитель'], $userId);
        if (!$manufacturerId) {
            throw new \Exception('Не удалось создать/найти производителя: ' . $data['Производитель']);
        }

        // Подготовка данных
        $productName = stripslashes($data['Наименование']);
        $slug = $this->generateSlug($productName, $vendorSku);
        
        // Основные данные товара
        $product = new \stdClass();
        $product->virtuemart_vendor_id = $vendorId;
        $product->product_sku = $vendorSku;
        $product->product_in_stock = (int)$data['Количество'];
        $product->created_by = $userId;
        $product->modified_by = $userId;
        $product->published = 1;
        
        // Создаем новый товар
        $this->db->insertObject('#__virtuemart_products', $product);
        $productId = $this->db->insertid();
        
        // Языковые данные
        $productLang = new \stdClass();
        $productLang->virtuemart_product_id = $productId;
        $productLang->product_name = $productName;
        $productLang->slug = $slug;
        $productLang->product_desc = '';
        $productLang->metadesc = '';
        
        $this->saveProductLang($productLang);
        
        // Обработка категорий
        $this->processCategories($data['Категория (Номер/ID/Название)'], $productId);
        
        // Обработка производителя
        $manufacturerId = $this->processManufacturer($data['Производитель'], $productId, $userId, $vendorId);

        // Обновляем цену
        $this->updateProductPrice($productId, $priceData, $vendorId);
        
        Log::add(sprintf('Товар создан: %s (ID: %d)', $vendorSku, $productId), Log::INFO, 'com_estakadaimport');
        
        return $productId;
    }

    /**
     * Обновление существующего товара
     */
    protected function updateExistingProduct($productId, $data, $vendorId, $userId, $priceData, $updatePricesOnly, $vendorSku)
    {
        Log::add(sprintf('Обновление товара: %s (ID: %d)', $vendorSku, $productId), Log::INFO, 'com_estakadaimport');

        // Основные данные товара
        $product = new \stdClass();
        $product->virtuemart_product_id = $productId;
        $product->modified_by = $userId;
        
        // В полном режиме обновляем все поля
        if (!$updatePricesOnly) {
            $product->product_in_stock = (int)$data['Количество'];
            
            // Обновляем языковые данные
            $productName = stripslashes($data['Наименование']);
            $slug = $this->generateSlug($productName, $vendorSku);
            
            $productLang = new \stdClass();
            $productLang->virtuemart_product_id = $productId;
            $productLang->product_name = $productName;
            $productLang->slug = $slug;
            
            $this->saveProductLang($productLang);
            
            // Обработка категорий
            $this->processCategories($data['Категория (Номер/ID/Название)'], $productId);
            
            // Обработка производителя
            $this->processManufacturer($data['Производитель'], $productId, $userId, $vendorId);
        }
        
        // Обновляем основную запись товара
        $this->db->updateObject('#__virtuemart_products', $product, 'virtuemart_product_id');
        
        // Обновляем цену (в любом режиме)
        $this->updateProductPrice($productId, $priceData, $vendorId);
        
        Log::add(sprintf('Товар обновлен: %s (ID: %d)', $vendorSku, $productId), Log::INFO, 'com_estakadaimport');
        
        return $productId;
    }

    /**
     * Метод для декодирования Unicode строк
     */
    protected function decodeUnicodeString($string)
    {
        if (empty($string)) {
            return $string;
        }
        
        // Декодируем JSON Unicode escape sequences
        if (preg_match('/\\\\u[0-9a-fA-F]{4}/', $string)) {
            $decoded = json_decode('"' . str_replace('"', '\\"', $string) . '"');
            return ($decoded !== null) ? $decoded : $string;
        }
        
        return $string;
    }

    /**
     * Сохранение языковых данных товара
     */
    protected function saveProductLang($productLang)
    {
        // Проверяем существование записи
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__virtuemart_products_ru_ru')
            ->where('virtuemart_product_id = ' . (int)$productLang->virtuemart_product_id);
        $exists = $this->db->setQuery($query)->loadResult();

        if ($exists) {
            $this->db->updateObject('#__virtuemart_products_ru_ru', $productLang, 'virtuemart_product_id');
        } else {
            $this->db->insertObject('#__virtuemart_products_ru_ru', $productLang);
        }
    }

    /**
     * Генерация slug
     */
    protected function generateSlug($name, $sku)
    {
        $slug = ApplicationHelper::stringURLSafe($name);
        if (empty($slug)) {
            $slug = ApplicationHelper::stringURLSafe($sku);
        }
        return $slug;
    }

    /**
     * Получение vendor_id пользователя с учетом выбора SuperUser
     */
    protected function getVendorId($userId, $selectedVendorId = null)
    {
        // Если SuperUser выбрал конкретного продавца
        if ($selectedVendorId && $this->isSuperUser($userId)) {
            // Проверяем, что выбранный vendor_id существует
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__virtuemart_vmusers')
                ->where('virtuemart_vendor_id = ' . (int)$selectedVendorId);
            
            if ($this->db->setQuery($query)->loadResult()) {
                return $selectedVendorId;
            }
        }
        
        // Обычный случай - возвращаем vendor_id пользователя
        $query = $this->db->getQuery(true)
            ->select('virtuemart_vendor_id')
            ->from('#__virtuemart_vmusers')
            ->where('virtuemart_user_id = ' . (int)$userId);
        
        return $this->db->setQuery($query)->loadResult();
    }

    /**
     * Получает список всех продавцов для SuperUser
     */
    public function getVendorsList()
    {
        $query = $this->db->getQuery(true)
            ->select([
                'vmu.virtuemart_vendor_id', 
                'vui.company',
                'u.name AS user_name',
                'u.username'
            ])
            ->from('#__virtuemart_vmusers AS vmu')
            ->join('INNER', '#__virtuemart_userinfos AS vui ON vmu.virtuemart_user_id = vui.virtuemart_user_id')
            ->join('INNER', '#__users AS u ON vmu.virtuemart_user_id = u.id')
            ->where('vmu.virtuemart_vendor_id IS NOT NULL')
            ->where('vmu.virtuemart_vendor_id != 0')
            ->order('vui.company ASC');
        
        return $this->db->setQuery($query)->loadObjectList();
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


    /**
     * Анализ Excel файла для подсчета изображений и строк
     */
    public function analyzeExcelFile($filePath)
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            
            // Получаем заголовки
            $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', NULL, TRUE, FALSE)[0];
            $columnMap = $this->createColumnMap($headers);
            
            // Получаем данные
            $rows = $sheet->toArray();
            $totalRows = count($rows) - 1; // minus headers
            
            // Подсчитываем общее количество изображений
            $totalImages = 0;
            $imageColumnIndex = $columnMap['product_image'] ?? null;
            
            if ($imageColumnIndex !== null) {
                for ($i = 1; $i < count($rows); $i++) {
                    $imageUrls = $rows[$i][$imageColumnIndex] ?? '';
                    if (!empty($imageUrls)) {
                        $urls = explode('|', $imageUrls);
                        $filteredUrls = array_filter($urls, function($url) {
                            return !empty(trim($url));
                        });
                        $totalImages += count($filteredUrls);
                    }
                }
            }
            
            return [
                'totalImages' => $totalImages,
                'totalRows' => $totalRows,
                'columnMap' => $columnMap
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Ошибка анализа файла: " . $e->getMessage());
        }
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
            
        $result = (int)$this->db->setQuery($query)->loadResult();
        
        // Log::add(sprintf('Default profile ID: %d', $result), Log::DEBUG, 'com_estakadaimport');
        
        return $result;
    }

    /**
     * Получает список профилей экспорта/импорта
     */
    public function getProfiles(): array
    {
        $query = $this->db->getQuery(true)
            ->select(['virtuemart_custom_id', 'custom_title'])
            ->from('#__virtuemart_customs')
            ->where('field_type = ' . $this->db->quote('G'));
            
        $results = $this->db->setQuery($query)->loadAssocList();
        
        $profiles = [];
        foreach ($results as $result) {
            $profiles[$result['virtuemart_custom_id']] = $result['custom_title'];
        }
        
        return $profiles;
    }

    /**
     * Проверяет соответствие заголовков Excel выбранному профилю
     */
    public function validateProfileMatch($profileId, $columnMap)
    {
        Log::add(sprintf('VALIDATE PROFILE START: profileId=%d', $profileId), Log::DEBUG, 'com_estakadaimport');
        
        if (!$profileId) {
            $profileId = $this->getDefaultProfileId();
            Log::add(sprintf('Using default profileId: %d', $profileId), Log::DEBUG, 'com_estakadaimport');
        }

        // Получаем только специфические поля профиля (исключаем универсальные)
        $profileFields = $this->getCustomFields($profileId);
        $profileFieldTitles = array_column($profileFields, 'custom_title');
        
        // Получаем универсальные поля (чтобы их исключить из проверки)
        $universalFields = $this->getUniversalFields();
        $universalFieldTitles = array_column($universalFields, 'custom_title');
        
        // Получаем все кастомные заголовки из Excel (кроме системных)
        $excelHeaders = array_keys($columnMap);
        $systemHeaders = ['categories', 'manufacturer', 'product_sku', 'product_name', 
                         'product_in_stock', 'product_image', 'product_price', 'price_override'];
        $customExcelHeaders = array_diff($excelHeaders, $systemHeaders);
        
        // Исключаем универсальные поля из Excel заголовков
        $specificExcelHeaders = array_diff($customExcelHeaders, $universalFieldTitles);
        
        Log::add(sprintf('Специфические поля профиля %d: %s', $profileId, implode(', ', $profileFieldTitles)), Log::DEBUG, 'com_estakadaimport');
        Log::add(sprintf('Универсальные поля: %s', implode(', ', $universalFieldTitles)), Log::DEBUG, 'com_estakadaimport');
        Log::add(sprintf('Специфические заголовки Excel: %s', implode(', ', $specificExcelHeaders)), Log::DEBUG, 'com_estakadaimport');
        
        // Если в Excel нет специфических полей - пропускаем проверку
        if (empty($specificExcelHeaders)) {
            Log::add('В Excel нет специфических полей (только универсальные) - пропускаем проверку профиля', Log::DEBUG, 'com_estakadaimport');
            return true;
        }
        
        // Проверяем, есть ли хотя бы одно специфическое поле профиля в Excel
        $matches = array_intersect($specificExcelHeaders, $profileFieldTitles);
        
        Log::add(sprintf('Найдено специфических совпадений: %d', count($matches)), Log::DEBUG, 'com_estakadaimport');
        Log::add(sprintf('Совпадения: %s', print_r($matches, true)), Log::DEBUG, 'com_estakadaimport');
        
        if (empty($matches)) {
            $profileName = $this->getProfileName($profileId);
            
            // Получаем подходящие профили (только по специфическим полям)
            $suitableProfiles = $this->findSuitableProfiles($specificExcelHeaders);
            
            $errorMessage = sprintf(
                'ERROR_PROFILE_MISMATCH: Выбран профиль "%s" (ID: %d), но файл содержит специфические поля для другого профиля. ',
                $profileName,
                $profileId
            );
            
            if (!empty($suitableProfiles)) {
                $errorMessage .= sprintf(
                    'Возможно, вам нужно выбрать один из этих профилей: %s. ',
                    implode(', ', array_slice($suitableProfiles, 0, 3)) // Показываем только первые 3
                );
            }
            
            $errorMessage .= 'Специфические поля в файле: ' . implode(', ', $specificExcelHeaders);
            
            Log::add('PROFILE MISMATCH: ' . $errorMessage, Log::ERROR, 'com_estakadaimport');
            
            // Устанавливаем прогресс с ошибкой
            $this->setImportProgress(0, 0, 'ERROR_PROFILE_MISMATCH');
            throw new \Exception($errorMessage);
        }
        
        Log::add('Проверка профиля пройдена успешно', Log::DEBUG, 'com_estakadaimport');
        return true;
    }

    /**
     * Получает название профиля по ID
     */
    protected function getProfileName($profileId)
    {
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select('custom_title')
            ->from('#__virtuemart_customs')
            ->where('virtuemart_custom_id = ' . (int)$profileId);
        
        return $db->setQuery($query)->loadResult() ?: 'Неизвестный профиль';
    }

    /**
     * Находит подходящие профили для данных заголовков (только специфические поля)
     */
    protected function findSuitableProfiles($specificExcelHeaders)
    {
        $allProfiles = $this->getProfiles();
        $suitableProfiles = [];
        
        foreach ($allProfiles as $profileId => $profileName) {
            $profileFields = $this->getCustomFields($profileId);
            $profileFieldTitles = array_column($profileFields, 'custom_title');
            
            $matches = array_intersect($specificExcelHeaders, $profileFieldTitles);
            
            if (!empty($matches)) {
                $suitableProfiles[] = $profileName;
            }
        }
        
        return $suitableProfiles;
    }

    /**
     * Генерация уникального артикула с префиксом vendor_id
     */
    protected function generateVendorSku($originalSku, $vendorId)
    {
        return 'i' . $vendorId . '__' . $originalSku;
    }

    /**
     * Проверяет, имеет ли артикул правильный префикс vendor_id
     */
    protected function hasVendorPrefix($sku, $vendorId)
    {
        $expectedPrefix = 'i' . $vendorId . '__';
        return strpos($sku, $expectedPrefix) === 0;
    }

    /**
     * Нормализует артикул - добавляет префикс vendor_id если его нет
     */
    protected function normalizeSku($sku, $vendorId)
    {
        // Если артикул уже имеет правильный префикс, возвращаем как есть
        if ($this->hasVendorPrefix($sku, $vendorId)) {
            return $sku;
        }
        
        // Если артикул имеет префикс другого vendor_id, исправляем его
        if (preg_match('/^i(\d+)__(.+)$/', $sku, $matches)) {
            return 'i' . $vendorId . '__' . $matches[2];
        }
        
        // Если артикул без префикса, добавляем наш префикс
        return 'i' . $vendorId . '__' . $sku;
    }

    /**
     * Получение оригинального артикула (без префикса vendor_id)
     */
    protected function getOriginalSku($vendorSku)
    {
        if (preg_match('/^i\d+__(.+)$/', $vendorSku, $matches)) {
            return $matches[1]; // Возвращаем оригинальный артикул без префикса
        }
        return $vendorSku; // Если формат не соответствует, возвращаем как есть
    }

    /**
     * Получение ID товара по артикулу с учетом vendor_id
     * Ищет товар по нормализованному артикулу
     */
    protected function getProductIdBySku($sku, $vendorId)
    {
        $normalizedSku = $this->normalizeSku($sku, $vendorId);
        
        $query = $this->db->getQuery(true)
            ->select('virtuemart_product_id')
            ->from('#__virtuemart_products')
            ->where('product_sku = ' . $this->db->quote($normalizedSku))
            ->where('virtuemart_vendor_id = ' . (int)$vendorId);
        
        return $this->db->setQuery($query)->loadResult();
    }

    /**
     * Поиск товара по оригинальному артикулу (без префикса) для данного vendor_id
     */
    protected function findProductByOriginalSku($originalSku, $vendorId)
    {
        $vendorSku = $this->generateVendorSku($originalSku, $vendorId);
        
        $query = $this->db->getQuery(true)
            ->select('virtuemart_product_id')
            ->from('#__virtuemart_products')
            ->where('product_sku = ' . $this->db->quote($vendorSku))
            ->where('virtuemart_vendor_id = ' . (int)$vendorId);
        
        return $this->db->setQuery($query)->loadResult();
    }

    /**
     * Миграция существующих товаров на новый формат артикулов
     * (можно запустить один раз для обновления старых записей)
     */
    public function migrateExistingSkus($vendorId)
    {
        try {
            // Получаем все товары продавца со старыми артикулами
            $query = $this->db->getQuery(true)
                ->select('virtuemart_product_id, product_sku')
                ->from('#__virtuemart_products')
                ->where('virtuemart_vendor_id = ' . (int)$vendorId)
                ->where('product_sku NOT LIKE ' . $this->db->quote('i' . $vendorId . '__%'));
            
            $products = $this->db->setQuery($query)->loadObjectList();
            
            $migrated = 0;
            foreach ($products as $product) {
                // Пропускаем артикулы, которые уже имеют префикс другого vendor_id
                if (preg_match('/^i\d+__/', $product->product_sku)) {
                    continue;
                }
                
                // Обновляем артикул
                $newSku = $this->generateVendorSku($product->product_sku, $vendorId);
                
                $updateQuery = $this->db->getQuery(true)
                    ->update('#__virtuemart_products')
                    ->set('product_sku = ' . $this->db->quote($newSku))
                    ->where('virtuemart_product_id = ' . (int)$product->virtuemart_product_id);
                
                $this->db->setQuery($updateQuery)->execute();
                $migrated++;
            }
            
            return sprintf('Мигрировано %d товаров', $migrated);
            
        } catch (\Exception $e) {
            throw new \Exception('Ошибка миграции артикулов: ' . $e->getMessage());
        }
    }






}