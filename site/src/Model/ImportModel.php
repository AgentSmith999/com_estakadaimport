<?php
namespace Joomla\Component\Estakadaimport\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseModel;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Image\Image;

// Подключаем автозагрузчик Composer
require_once JPATH_ROOT . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportModel extends BaseModel
{
    protected $db;
    protected $logOptions = [
        'text_file' => 'com_estakadaimport.log',
        'text_entry_format' => '{DATETIME} {PRIORITY} {MESSAGE}'
    ];

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
    public function importFromExcel($filePath)
    {
        $user = Factory::getUser();
        $app = Factory::getApplication();

        // Проверяем авторизацию
        if ($user->guest) {
            $message = 'Доступ запрещен. Необходима авторизация.';
            Log::add($message, Log::ERROR, 'com_estakadaimport');
            $app->enqueueMessage($message, 'error');
            return false;
        }

        // Получаем vendor_id
        $vendorId = $this->getVendorId($user->id);
        if (empty($vendorId)) {
            $message = 'Пользователь не является продавцом.';
            Log::add($message, Log::ERROR, 'com_estakadaimport');
            $app->enqueueMessage($message, 'error');
            return false;
        }

        try {
            Log::add('Начало импорта для vendor_id: ' . $vendorId, Log::INFO, 'com_estakadaimport');
            
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            
            // Получаем заголовки
            $headers = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', NULL, TRUE, FALSE)[0];
            $columnMap = $this->createColumnMap($headers);
            
            // Получаем данные
            $rows = $sheet->toArray();
            if (count($rows) <= 1) {
                throw new \Exception("Файл не содержит данных для импорта");
            }
            array_shift($rows);

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

            error_log('Total images to process: ' . $this->totalImages);
            
            $this->currentImageIndex = 0;
            
            // Устанавливаем начальный прогресс
            $this->setImportProgress(0, $this->totalImages, 'Подготовка к импорту...');

            error_log('Initial progress set');

            $count = 0;
            $errors = 0;
            
            foreach ($rows as $rowIndex => $row) {
                try {
                    $data = [
                        'Артикул' => $row[$columnMap['product_sku']] ?? '',
                        'Наименование' => $row[$columnMap['product_name']] ?? '',
                        'Количество' => $row[$columnMap['product_in_stock']] ?? 0,
                        'Изображение' => $row[$columnMap['product_image']] ?? '',
                        'Категория (Номер/ID/Название)' => $row[$columnMap['categories']] ?? ''
                    ];
                    
                    Log::add(sprintf('Обработка строки %d: %s', $rowIndex + 2, json_encode($data)), 
                        Log::DEBUG, 'com_estakadaimport');

                    // Проверяем обязательные поля
                    if (empty($data['Артикул'])) {
                        throw new \Exception('Не указан артикул');
                    }
                    
                    if (empty($data['Наименование'])) {
                        throw new \Exception('Не указано наименование');
                    }
                    
                    if (empty($data['Категория (Номер/ID/Название)'])) {
                        throw new \Exception('Не указана категория');
                    }

                    // Обработка товара
                    $productId = $this->processProduct($data, $vendorId, $user->id);
                    
                    if ($productId) {
                        // Обработка изображений
                        if (!empty($data['Изображение'])) {
                            $this->processImages($data['Изображение'], $productId, $vendorId, $user->id);
                        }
                        
                        $count++;
                        Log::add(sprintf('Товар %s обработан (ID: %d)', $data['Артикул'], $productId), 
                            Log::INFO, 'com_estakadaimport');
                    }

                   // $this->clearImportProgress();
                   // return $errors === 0;
                    
                } catch (\Exception $e) {
                    // $this->clearImportProgress();
                    $errors++;
                    Log::add(sprintf('Ошибка в строке %d: %s', $rowIndex + 2, $e->getMessage()), 
                        Log::ERROR, 'com_estakadaimport');
                }
            }

            $message = sprintf('Импорт завершен. Успешно: %d, с ошибками: %d', $count, $errors);
            Log::add($message, ($errors ? Log::WARNING : Log::INFO), 'com_estakadaimport');
            $app->enqueueMessage($message, ($errors ? 'warning' : 'message'));
            
            $this->clearImportProgress();
            return $errors === 0;

        } catch (\Exception $e) {
            $this->clearImportProgress();
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
            $header = trim($header);
            Log::add(sprintf('Заголовок %d: %s', $index, $header), Log::DEBUG, 'com_estakadaimport');
            
            switch ($header) {
                case 'Артикул': $map['product_sku'] = $index; break;
                case 'Наименование товара': $map['product_name'] = $index; break;
                case 'Количество на складе': $map['product_in_stock'] = $index; break;
                case 'Изображение': $map['product_image'] = $index; break;
                case 'Категория (Номер/ID/Название)': $map['categories'] = $index; break;
            }
        }
        
        // Логируем найденные колонки
        Log::add('Найденные колонки: ' . json_encode($map), Log::DEBUG, 'com_estakadaimport');
        
        if (!isset($map['product_sku'])) {
            throw new \Exception('Не найден обязательный столбец "Артикул"');
        }
         if (!isset($map['categories'])) {
            throw new \Exception('Не найден обязательный столбец "Категория (Номер/ID/Название)"');
        }
        
        return $map;
    }

    /**
     * Проверка существующих изображений товара
     */
    protected function hasExistingImages($productId)
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__virtuemart_product_medias AS pm')
            ->join('INNER', '#__virtuemart_medias AS m ON m.virtuemart_media_id = pm.virtuemart_media_id')
            ->where('pm.virtuemart_product_id = ' . (int)$productId)
            ->where('m.file_is_product_image = 1');
        
        return $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Удаление существующих изображений товара
     */
    protected function deleteExistingImages($productId, $vendorId)
    {
        // Получаем ID медиафайлов товара
        $query = $this->db->getQuery(true)
            ->select('pm.virtuemart_media_id, m.file_url, m.file_url_thumb')
            ->from('#__virtuemart_product_medias AS pm')
            ->join('INNER', '#__virtuemart_medias AS m ON m.virtuemart_media_id = pm.virtuemart_media_id')
            ->where('pm.virtuemart_product_id = ' . (int)$productId)
            ->where('m.file_is_product_image = 1');
        
        $mediaFiles = $this->db->setQuery($query)->loadObjectList();
        
        foreach ($mediaFiles as $media) {
            // Удаляем файлы изображений
            if (!empty($media->file_url) && file_exists(JPATH_SITE . '/' . $media->file_url)) {
                @unlink(JPATH_SITE . '/' . $media->file_url);
            }
            if (!empty($media->file_url_thumb) && file_exists(JPATH_SITE . '/' . $media->file_url_thumb)) {
                @unlink(JPATH_SITE . '/' . $media->file_url_thumb);
            }
            
            // Удаляем запись из таблицы связей товаров и медиафайлов
            $query = $this->db->getQuery(true)
                ->delete('#__virtuemart_product_medias')
                ->where('virtuemart_product_id = ' . (int)$productId)
                ->where('virtuemart_media_id = ' . (int)$media->virtuemart_media_id);
            $this->db->setQuery($query)->execute();
            
            // Удаляем запись из таблицы медиафайлов
            $query = $this->db->getQuery(true)
                ->delete('#__virtuemart_medias')
                ->where('virtuemart_media_id = ' . (int)$media->virtuemart_media_id);
            $this->db->setQuery($query)->execute();
        }
        
        Log::add(sprintf('Удалено %d существующих изображений товара %d', count($mediaFiles), $productId), 
            Log::DEBUG, 'com_estakadaimport');
    }

    /**
     * Обработка изображений товара
     */
    protected function processImages($imageUrls, $productId, $vendorId, $userId)
    {
        if (empty($imageUrls)) {
            Log::add('Нет URL изображений для обработки', Log::DEBUG, 'com_estakadaimport');
            return;
        }

        Log::add(sprintf('Начало обработки изображений для товара %d: %s', $productId, $imageUrls), 
            Log::DEBUG, 'com_estakadaimport');

        // Если у товара уже есть изображения - удаляем их
        if ($this->hasExistingImages($productId)) {
            Log::add('У товара уже есть изображения - удаляем старые', Log::DEBUG, 'com_estakadaimport');
            $this->deleteExistingImages($productId, $vendorId);
        }

        // Разделяем URL изображений
        $urls = is_array($imageUrls) ? $imageUrls : explode('|', $imageUrls);
        $urls = array_filter($urls, function($url) {
            return !empty(trim($url));
        });

        error_log('Processing ' . count($urls) . ' images for product ' . $productId);

        $lastDownloadTime = 0;
        
        foreach ($urls as $index => $url) {
            $url = trim($url);
            if (empty($url)) {
                Log::add('Пустой URL изображения', Log::DEBUG, 'com_estakadaimport');
                continue;
            }

            // ОБНОВЛЯЕМ ПРОГРЕСС - это ключевой момент!
            $this->currentImageIndex++;
            error_log("Processing image {$this->currentImageIndex}/{$this->totalImages}: {$url}");
            
            $this->setImportProgress($this->currentImageIndex, $this->totalImages, $url);

            // Проверяем, является ли URL локальным файлом (без http/https)
            if (!$this->isExternalUrl($url)) {
                Log::add(sprintf('Пропускаем локальный файл: %s', $url), Log::DEBUG, 'com_estakadaimport');
                
                // Проверяем существование локального файла
                $localFilePath = JPATH_SITE . '/images/virtuemart/product/' . $vendorId . '/' . $url;
                if (file_exists($localFilePath)) {
                    Log::add(sprintf('Локальный файл существует: %s', $localFilePath), Log::DEBUG, 'com_estakadaimport');
                    
                    // Сохраняем информацию о существующем медиафайле
                    $mediaId = $this->saveMediaInfo($url, $productId, $vendorId, $userId);
                    Log::add(sprintf('Медиафайл сохранен с ID: %d', $mediaId), Log::DEBUG, 'com_estakadaimport');
                } else {
                    Log::add(sprintf('Локальный файл не существует: %s', $localFilePath), Log::WARNING, 'com_estakadaimport');
                }
                
                continue;
            }

            // Добавляем задержку между загрузками изображений (3 секунды)
            $currentTime = microtime(true);
            $timeSinceLastDownload = $currentTime - $lastDownloadTime;
            
            if ($timeSinceLastDownload < 3 && $lastDownloadTime > 0) {
                $sleepTime = 3 - $timeSinceLastDownload;
                Log::add(sprintf('Пауза %s сек перед загрузкой следующего изображения', round($sleepTime, 1)), 
                    Log::DEBUG, 'com_estakadaimport');
                usleep($sleepTime * 1000000); // microseconds
            }

            try {
                Log::add(sprintf('Обработка изображения %d: %s', $index + 1, $url), 
                    Log::DEBUG, 'com_estakadaimport');
                
                // Скачиваем и обрабатываем изображение
                $imageName = $this->downloadAndProcessImage($url, $vendorId);
                
                if ($imageName) {
                    Log::add(sprintf('Изображение обработано: %s', $imageName), 
                        Log::DEBUG, 'com_estakadaimport');
                    
                    // Сохраняем информацию о медиафайле
                    $mediaId = $this->saveMediaInfo($imageName, $productId, $vendorId, $userId);
                    
                    Log::add(sprintf('Медиафайл сохранен с ID: %d', $mediaId), 
                        Log::DEBUG, 'com_estakadaimport');
                        
                    $lastDownloadTime = microtime(true);
                } else {
                    Log::add('Не удалось обработать изображение', Log::WARNING, 'com_estakadaimport');
                }
                
            } catch (\Exception $e) {
                Log::add(sprintf('Ошибка обработки изображения %s: %s', $url, $e->getMessage()), 
                    Log::ERROR, 'com_estakadaimport');
                    
                // Все равно обновляем прогресс, даже при ошибке
                $this->setImportProgress($this->currentImageIndex, $this->totalImages, "[ОШИБКА] " . $url);
            }
        }
    }

    /**
     * Проверяет, является ли URL внешним (с http/https)
     */
    protected function isExternalUrl($url)
    {
        $url = strtolower(trim($url));
        return (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
    }

    /**
     * Скачивание и обработка изображения
     */
    protected function downloadAndProcessImage($imageUrl, $vendorId)
    {
        Log::add(sprintf('Скачивание изображения: %s', $imageUrl), Log::DEBUG, 'com_estakadaimport');

        // Проверяем, что URL действительно внешний
        if (!$this->isExternalUrl($imageUrl)) {
            throw new \Exception("URL не является внешним: " . $imageUrl);
        }

        // Проверяем URL
        $headers = @get_headers($imageUrl, 1);
        if (!$headers) {
            throw new \Exception("Не удалось получить заголовки для URL: " . $imageUrl);
        }
        
        Log::add(sprintf('Ответ сервера: %s', $headers[0]), Log::DEBUG, 'com_estakadaimport');

        if (is_array($headers[0])) {
            $firstHeader = $headers[0][0] ?? '';
        } else {
            $firstHeader = $headers[0];
        }
        
        if (stripos($firstHeader, '200 OK') === false) {
            throw new \Exception("Не удалось получить изображение по URL: " . $imageUrl . " - " . $headers[0]);
        }

        // Создаем директории
        $basePath = JPATH_SITE . '/images/virtuemart/product/' . $vendorId;
        $resizedPath = $basePath . '/resized';
        
        if (!file_exists($basePath)) {
            if (!mkdir($basePath, 0755, true)) {
                throw new \Exception("Не удалось создать директорию: " . $basePath);
            }
        }
        if (!file_exists($resizedPath)) {
            if (!mkdir($resizedPath, 0755, true)) {
                throw new \Exception("Не удалось создать директорию: " . $resizedPath);
            }
        }

        // Получаем оригинальное имя файла из URL
        $originalFileName = basename(parse_url($imageUrl, PHP_URL_PATH));
        $extension = pathinfo($originalFileName, PATHINFO_EXTENSION);
        $fileName = $originalFileName;
        
        // Если имя файла пустое или слишком длинное, генерируем нормальное
        if (empty($fileName) || strlen($fileName) > 100) {
            $fileName = 'img_' . uniqid() . '.' . ($extension ?: 'jpg');
        }
        
        Log::add(sprintf('Имя файла: %s', $fileName), Log::DEBUG, 'com_estakadaimport');
        
        // Временный файл
        $tempDir = JPATH_SITE . '/tmp';
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir . '/' . $fileName;
        
        // Скачиваем изображение
        $imageData = @file_get_contents($imageUrl);
        if ($imageData === false) {
            throw new \Exception("Не удалось скачать изображение");
        }
        
        file_put_contents($tempFile, $imageData);
        
        if (!file_exists($tempFile)) {
            throw new \Exception("Не удалось сохранить временный файл");
        }

        // Обрабатываем основное изображение (600x600)
        try {
            $image = new Image($tempFile);
            $image->resize(600, 600, true, Image::SCALE_OUTSIDE);
            $image->toFile($basePath . '/' . $fileName, IMAGETYPE_JPEG, ['quality' => 85]);
        } catch (\Exception $e) {
            throw new \Exception("Ошибка обработки основного изображения: " . $e->getMessage());
        }

        // Обрабатываем миниатюру (250x250)
        try {
            $thumb = new Image($tempFile);
            $thumb->resize(250, 250, true, Image::SCALE_OUTSIDE);
            
            // Имя для миниатюры: originalname_250x250.extension
            $thumbName = pathinfo($fileName, PATHINFO_FILENAME) . '_250x250.' . $extension;
            $thumb->toFile($resizedPath . '/' . $thumbName, IMAGETYPE_JPEG, ['quality' => 85]);
        } catch (\Exception $e) {
            Log::add('Ошибка создания миниатюры: ' . $e->getMessage(), Log::WARNING, 'com_estakadaimport');
        }

        // Удаляем временный файл
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        return $fileName;
    }

    /**
     * Сохранение информации о медиафайле
     */
    protected function saveMediaInfo($fileName, $productId, $vendorId, $userId)
    {
        Log::add(sprintf('Сохранение информации о медиафайле: %s', $fileName), Log::DEBUG, 'com_estakadaimport');
        
        $fileTitle = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        // URL основного изображения и миниатюры
        $fileUrl = 'images/virtuemart/product/' . $vendorId . '/' . $fileName;
        $fileUrlThumb = 'images/virtuemart/product/' . $vendorId . '/resized/' . pathinfo($fileName, PATHINFO_FILENAME) . '_250x250.' . $extension;
        
        // Сохраняем в таблицу медиафайлов
        $media = new \stdClass();
        $media->virtuemart_vendor_id = $vendorId;
        $media->file_title = $fileTitle;
        $media->file_type = 'product';
        $media->file_mimetype = 'image/jpeg';
        $media->file_url = $fileUrl;
        $media->file_url_thumb = $fileUrlThumb;
        $media->created_by = $userId;
        $media->modified_by = $userId;
        $media->file_is_product_image = 1;
        
        Log::add('Данные медиафайла: ' . json_encode($media), Log::DEBUG, 'com_estakadaimport');
        
        try {
            $result = $this->db->insertObject('#__virtuemart_medias', $media);
            $mediaId = $this->db->insertid();
            
            Log::add(sprintf('Медиафайл сохранен с ID: %d', $mediaId), Log::DEBUG, 'com_estakadaimport');
            
            // Связываем медиафайл с товаром
            $productMedia = new \stdClass();
            $productMedia->virtuemart_product_id = $productId;
            $productMedia->virtuemart_media_id = $mediaId;
            
            Log::add('Данные связи: ' . json_encode($productMedia), Log::DEBUG, 'com_estakadaimport');
            
            $this->db->insertObject('#__virtuemart_product_medias', $productMedia);
            
            Log::add('Связь товара с медиафайлом сохранена', Log::DEBUG, 'com_estakadaimport');
            
            return $mediaId;
            
        } catch (\Exception $e) {
            Log::add('Ошибка сохранения медиафайла: ' . $e->getMessage(), Log::ERROR, 'com_estakadaimport');
            throw $e;
        }
    }

    /**
     * Обработка товара
     * В методе processProduct - если категория не найдена, НЕ создаем товар
     */
    protected function processProduct($data, $vendorId, $userId)
    {
        // Обновляем прогресс - этап обработки товара
        $this->setImportProgress(
            $this->currentImageIndex, 
            $this->totalImages, 'Обработка товара: ' . ($data['Артикул'] ?? '')
        );

        // Проверяем категорию ДО создания товара
        if (empty($data['Категория (Номер/ID/Название)'])) {
            throw new \Exception('Категория не указана для товара');
        }

        $categoryId = $this->getCategoryId($data['Категория (Номер/ID/Название)']);
        if (!$categoryId) {
            throw new \Exception('Категория не найдена: ' . $data['Категория (Номер/ID/Название)']);
        }

        // Подготовка данных
        $productName = stripslashes($data['Наименование']);
        $slug = $this->generateSlug($productName, $data['Артикул']);
        
        // Основные данные товара
        $product = new \stdClass();
        $product->virtuemart_vendor_id = $vendorId;
        $product->product_sku = $data['Артикул'];
        $product->product_in_stock = (int)$data['Количество'];
        $product->created_by = $userId;
        $product->modified_by = $userId;
        $product->published = 1;
        
        // Проверяем существование товара
        $productId = $this->getProductIdBySku($data['Артикул']);
        
        if ($productId) {
            $product->virtuemart_product_id = $productId;
            $this->db->updateObject('#__virtuemart_products', $product, 'virtuemart_product_id');
        } else {
            $this->db->insertObject('#__virtuemart_products', $product);
            $productId = $this->db->insertid();
        }
        
        // Языковые данные
        $productLang = new \stdClass();
        $productLang->virtuemart_product_id = $productId;
        $productLang->product_name = $productName;
        $productLang->slug = $slug;
        $productLang->product_desc = '';
        $productLang->metadesc = '';
        
        $this->saveProductLang($productLang);

        // Обработка категорий - теперь категория гарантированно существует
        $this->processCategories($categoryId, $productId);
        
        return $productId;
    }

    // Новый метод для обработки категорий
    protected function processCategories($categoryId, $productId)
    {
        Log::add(sprintf('Привязка товара %d к категории %d', $productId, $categoryId), 
            Log::DEBUG, 'com_estakadaimport');
        
        // Удаляем старые связи товара с категориями
        $this->removeProductCategories($productId);
        
        // Создаем новую связь
        $this->addProductToCategory($productId, $categoryId);
        
        Log::add(sprintf('Товар %d привязан к категории %d', $productId, $categoryId), 
            Log::INFO, 'com_estakadaimport');
    }


    // Метод для получения ID категории по номеру/ID/названию (более надежная версия)
    protected function getCategoryId($categoryInfo)
    {
        $categoryInfo = trim($categoryInfo);
        
        if (empty($categoryInfo)) {
            return null;
        }
        
        Log::add(sprintf('Поиск категории: %s', $categoryInfo), Log::DEBUG, 'com_estakadaimport');
        
        // Пробуем найти по ID (если передан числовой ID)
        if (is_numeric($categoryInfo)) {
            $categoryId = $this->getCategoryById((int)$categoryInfo);
            if ($categoryId) {
                Log::add(sprintf('Категория найдена по ID %d: %d', $categoryInfo, $categoryId), 
                    Log::DEBUG, 'com_estakadaimport');
                return $categoryId;
            }
        }
        
        // Пробуем найти по названию (точное совпадение)
        $categoryId = $this->getCategoryByName($categoryInfo);
        if ($categoryId) {
            Log::add(sprintf('Категория найдена по названию "%s": %d', $categoryInfo, $categoryId), 
                Log::DEBUG, 'com_estakadaimport');
            return $categoryId;
        }
        
        // Пробуем найти по названию (похожее, без учета регистра)
        $categoryId = $this->getCategoryByNameLike($categoryInfo);
        if ($categoryId) {
            Log::add(sprintf('Категория найдена по похожему названию "%s": %d', $categoryInfo, $categoryId), 
                Log::DEBUG, 'com_estakadaimport');
            return $categoryId;
        }
        
        Log::add(sprintf('Категория не найдена: %s', $categoryInfo), Log::WARNING, 'com_estakadaimport');
        return null;
    }

    // Поиск категории по ID
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

    // Поиск категории по названию (точное совпадение)
    protected function getCategoryByName($categoryName)
    {
        $query = $this->db->getQuery(true)
            ->select('c.virtuemart_category_id')
            ->from('#__virtuemart_categories AS c')
            ->join('INNER', '#__virtuemart_categories_ru_ru AS cl ON c.virtuemart_category_id = cl.virtuemart_category_id')
            ->where('cl.category_name = ' . $this->db->quote($categoryName))
            ->where('c.published = 1');
        
        return $this->db->setQuery($query)->loadResult();
    }

    // Поиск категории по названию (похожее, без учета регистра)
    protected function getCategoryByNameLike($categoryName)
    {
        $query = $this->db->getQuery(true)
            ->select('c.virtuemart_category_id')
            ->from('#__virtuemart_categories AS c')
            ->join('INNER', '#__virtuemart_categories_ru_ru AS cl ON c.virtuemart_category_id = cl.virtuemart_category_id')
            ->where('LOWER(cl.category_name) = LOWER(' . $this->db->quote($categoryName) . ')')
            ->where('c.published = 1');
        
        return $this->db->setQuery($query)->loadResult();
    }

    // Удаление всех связей товара с категориями
    protected function removeProductCategories($productId)
    {
        $query = $this->db->getQuery(true)
            ->delete('#__virtuemart_product_categories')
            ->where('virtuemart_product_id = ' . (int)$productId);
        
        $this->db->setQuery($query)->execute();
        
        Log::add(sprintf('Удалены старые связи товара %d с категориями', $productId), 
            Log::DEBUG, 'com_estakadaimport');
    }

    // Добавление товара в категорию
    protected function addProductToCategory($productId, $categoryId)
    {
        $productCategory = new \stdClass();
        $productCategory->virtuemart_product_id = $productId;
        $productCategory->virtuemart_category_id = $categoryId;
        
        try {
            $this->db->insertObject('#__virtuemart_product_categories', $productCategory);
            return true;
        } catch (\Exception $e) {
            Log::add(sprintf('Ошибка привязки товара %d к категории %d: %s', 
                $productId, $categoryId, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
            return false;
        }
    }

    /**
     * Получение ID товара по артикулу
     */
    protected function getProductIdBySku($sku)
    {
        $query = $this->db->getQuery(true)
            ->select('virtuemart_product_id')
            ->from('#__virtuemart_products')
            ->where('product_sku = ' . $this->db->quote($sku));
        return $this->db->setQuery($query)->loadResult();
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
     * Получение vendor_id пользователя
     */
    protected function getVendorId($userId)
    {
        $query = $this->db->getQuery(true)
            ->select('virtuemart_vendor_id')
            ->from('#__virtuemart_vmusers')
            ->where('virtuemart_user_id = ' . (int)$userId);
        return $this->db->setQuery($query)->loadResult();
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
                    Log::add(sprintf('Строка %d, изображения: %s', $i, $imageUrls), Log::DEBUG, 'com_estakadaimport');
                    if (!empty($imageUrls)) {
                        $urls = explode('|', $imageUrls);
                        $filteredUrls = array_filter($urls, function($url) {
                            return !empty(trim($url));
                        });
                        $totalImages += count($filteredUrls);
                        Log::add(sprintf('Найдено %d изображений в строке %d', count($filteredUrls), $i), Log::DEBUG, 'com_estakadaimport');
                    }
                }
            }

            Log::add(sprintf('Итого: %d строк, %d изображений', $totalRows, $totalImages), Log::INFO, 'com_estakadaimport');
            
            return [
                'totalImages' => $totalImages,
                'totalRows' => $totalRows,
                'columnMap' => $columnMap
            ];
            
        } catch (\Exception $e) {
            throw new \Exception("Ошибка анализа файла: " . $e->getMessage());
        }
    }

    // Добавим в класс свойства
    protected $totalImages = 0;
    protected $currentImageIndex = 0;

    /**
     * Установка прогресса импорта (файловая версия)
     */
    protected function setImportProgress($current, $total, $currentImage = '')
    {
        try {
            $user = Factory::getUser();

            // Рассчитываем скорость обработки
            static $lastUpdateTime = 0;
            static $lastProcessed = 0;
            
            $currentTime = microtime(true);
            $processingSpeed = 0;
            
            if ($lastUpdateTime > 0 && $lastProcessed > 0 && $current > $lastProcessed) {
                $timeDiff = $currentTime - $lastUpdateTime;
                $processedDiff = $current - $lastProcessed;
                $processingSpeed = $timeDiff / $processedDiff;
            }
            
            $lastUpdateTime = $currentTime;
            $lastProcessed = $current;


            $progress = [
                'current' => $current,
                'total' => $total,
                'currentImage' => $currentImage,
                'timestamp' => time(),
                'user_id' => $user->id,
                'percentage' => $total > 0 ? round(($current / $total) * 100) : 0
            ];
            
            // Создаем временную директорию если не существует
            $tmpDir = JPATH_SITE . '/tmp/estakada_import';
            if (!file_exists($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }
            
            $cacheFile = $tmpDir . '/progress_' . $user->id . '.json';
            $result = file_put_contents($cacheFile, json_encode($progress));
            
            if ($result === false) {
                error_log('Failed to write progress file: ' . $cacheFile);
            } else {
                error_log('Progress updated: ' . $current . '/' . $total . ' - ' . $currentImage);
            }
            
        } catch (\Exception $e) {
            error_log('Error setting file progress: ' . $e->getMessage());
        }
    }

    /**
     * Получение прогресса импорта (файловая версия)
     */
    public function getImportProgress()
    {
        try {
            $user = Factory::getUser();
            $tmpDir = JPATH_SITE . '/tmp/estakada_import';
            $cacheFile = $tmpDir . '/progress_' . $user->id . '.json';
            
            error_log('Looking for progress file: ' . $cacheFile);
            
            if (file_exists($cacheFile)) {
                $content = file_get_contents($cacheFile);
                $progress = json_decode($content, true);
                
                error_log('Progress file content: ' . $content);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log('JSON decode error: ' . json_last_error_msg());
                    return [];
                }
                
                // Если прогресс устарел (больше 10 минут)
                if (!empty($progress) && (time() - $progress['timestamp']) > 600) {
                    error_log('Progress expired, deleting file');
                    unlink($cacheFile);
                    return [];
                }
                
                error_log('Returning progress data: ' . json_encode($progress));
                return $progress;
            }
            
            error_log('No progress file found');
            return [];
            
        } catch (\Exception $e) {
            error_log('Error getting file progress: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Очистка прогресса импорта (файловая версия)
     */
    public function clearImportProgress()
    {
        try {
            $user = Factory::getUser();
            $tmpDir = JPATH_SITE . '/tmp/estakada_import';
            $cacheFile = $tmpDir . '/progress_' . $user->id . '.json';
            
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
                error_log('Progress file cleared: ' . $cacheFile);
            }
            
        } catch (\Exception $e) {
            error_log('Error clearing file progress: ' . $e->getMessage());
        }
    }


}
?>