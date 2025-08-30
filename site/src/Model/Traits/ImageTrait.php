<?php
namespace Joomla\Component\Estakadaimport\Site\Model\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Log\Log;
use Joomla\CMS\Image\Image;

trait ImageTrait
{
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
        
        // Log::add(sprintf('Удалено %d существующих изображений товара %d', count($mediaFiles), $productId), Log::DEBUG, 'com_estakadaimport');
    }

    /**
     * Обработка изображений товара
     */
    protected function processImages($imageUrls, $productId, $vendorId, $userId)
    {
        if (empty($imageUrls)) {
            // Log::add('Нет URL изображений для обработки', Log::DEBUG, 'com_estakadaimport');
            return;
        }

        // Log::add(sprintf('Начало обработки изображений для товара %d: %s', $productId, $imageUrls), Log::DEBUG, 'com_estakadaimport');

        // Если у товара уже есть изображения - удаляем их
        if ($this->hasExistingImages($productId)) {
            // Log::add('У товара уже есть изображения - удаляем старые', Log::DEBUG, 'com_estakadaimport');
            $this->deleteExistingImages($productId, $vendorId);
        }

        // Разделяем URL изображений
        $urls = is_array($imageUrls) ? $imageUrls : explode('|', $imageUrls);
        $urls = array_filter($urls, function($url) {
            return !empty(trim($url));
        });

        error_log('Processing ' . count($urls) . ' images for product ' . $productId);

        $lastDownloadTime = 0;
        $skippedGifs = [];
        
        foreach ($urls as $index => $url) {
            $url = trim($url);
            if (empty($url)) {
                // Log::add('Пустой URL изображения', Log::DEBUG, 'com_estakadaimport');
                continue;
            }

            // ОБНОВЛЯЕМ ПРОГРЕСС
            $this->currentImageIndex++;
            error_log("Processing image {$this->currentImageIndex}/{$this->totalImages}: {$url}");
            
            $this->setImportProgress($this->currentImageIndex, $this->totalImages, $url);

            // Log::add(sprintf('Обработано изображение %d из %d', $this->currentImageIndex, $this->totalImages), Log::DEBUG, 'com_estakadaimport');

            // Проверяем, является ли URL локальным файлом (без http/https)
            if (!$this->isExternalUrl($url)) {
                // Log::add(sprintf('Пропускаем локальный файл: %s', $url), Log::DEBUG, 'com_estakadaimport');
                
                // Проверяем существование локального файла
                $localFilePath = JPATH_SITE . '/images/virtuemart/product/' . $vendorId . '/' . $url;
                if (file_exists($localFilePath)) {
                    // Log::add(sprintf('Локальный файл существует: %s', $localFilePath), Log::DEBUG, 'com_estakadaimport');
                    
                    // Сохраняем информацию о существующем медиафайле
                    $mediaId = $this->saveMediaInfo($url, $productId, $vendorId, $userId);
                    // Log::add(sprintf('Медиафайл сохранен с ID: %d', $mediaId), Log::DEBUG, 'com_estakadaimport');
                } else {
                    // Log::add(sprintf('Локальный файл не существует: %s', $localFilePath), Log::WARNING, 'com_estakadaimport');
                }
                
                continue;
            }

            // Добавляем задержку между загрузками изображений (3 секунды)
            $currentTime = microtime(true);
            $timeSinceLastDownload = $currentTime - $lastDownloadTime;
            
            if ($timeSinceLastDownload < 3 && $lastDownloadTime > 0) {
                $sleepTime = 3 - $timeSinceLastDownload;
                // Log::add(sprintf('Пауза %s сек перед загрузкой следующего изображения', round($sleepTime, 1)), Log::DEBUG, 'com_estakadaimport');
                usleep($sleepTime * 1000000); // microseconds
            }

            try {
                // Log::add(sprintf('Обработка изображения %d: %s', $index + 1, $url), Log::DEBUG, 'com_estakadaimport');
                
                // Скачиваем и обрабатываем изображение
                $imageName = $this->downloadAndProcessImage($url, $vendorId);
                
                if ($imageName) {
                    // Log::add(sprintf('Изображение обработано: %s', $imageName), Log::DEBUG, 'com_estakadaimport');
                    
                    // Сохраняем информацию о медиафайле
                    $mediaId = $this->saveMediaInfo($imageName, $productId, $vendorId, $userId);
                    
                    // Log::add(sprintf('Медиафайл сохранен с ID: %d', $mediaId), Log::DEBUG, 'com_estakadaimport');
                        
                    $lastDownloadTime = microtime(true);
                } else {
                    // Log::add('Не удалось обработать изображение', Log::WARNING, 'com_estakadaimport');
                }
                
            } catch (\Exception $e) {
                // Проверяем, если это GIF - добавляем в список пропущенных
                if (strpos($e->getMessage(), 'GIF') !== false || stripos($url, '.gif') !== false) {
                    $skippedGifs[] = $url;
                    // Log::add(sprintf('Пропущен GIF: %s - %s', $url, $e->getMessage()), Log::WARNING, 'com_estakadaimport');
                } else {
                    // Log::add(sprintf('Ошибка обработки изображения %s: %s', $url, $e->getMessage()), Log::ERROR, 'com_estakadaimport');
                }
                
                // Все равно обновляем прогресс, даже при ошибке
                $this->setImportProgress($this->currentImageIndex, $this->totalImages, "[ОШИБКА] " . $url);
            }
        }
        
        // Логируем пропущенные GIF-файлы
        if (!empty($skippedGifs)) {
            // Log::add(sprintf('Пропущено %d GIF-изображений для товара %d: %s', count($skippedGifs), $productId, implode(', ', $skippedGifs)), Log::WARNING, 'com_estakadaimport');
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
        // Log::add(sprintf('Скачивание изображения: %s', $imageUrl), Log::DEBUG, 'com_estakadaimport');

        // Проверяем, что URL действительно внешний
        if (!$this->isExternalUrl($imageUrl)) {
            throw new \Exception("URL не является внешним: " . $imageUrl);
        }

        // Проверяем URL
        $headers = @get_headers($imageUrl, 1);
        if (!$headers) {
            throw new \Exception("Не удалось получить заголовки для URL: " . $imageUrl);
        }
        
        // Log::add(sprintf('Ответ сервера: %s', $headers[0]), Log::DEBUG, 'com_estakadaimport');

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
        $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION)); // Приводим к нижнему регистру
        
        // Проверяем поддерживаемые форматы
        if ($extension === 'gif') {
            throw new \Exception("Формат GIF не поддерживается");
        }
        
        // Всегда сохраняем как JPG
        $fileName = pathinfo($originalFileName, PATHINFO_FILENAME) . '.jpg';
        
        // Если имя файла пустое или слишком длинное, генерируем нормальное
        if (empty($fileName) || strlen($fileName) > 100) {
            $fileName = 'img_' . uniqid() . '.jpg';
        }
        
        // Log::add(sprintf('Имя файла: %s', $fileName), Log::DEBUG, 'com_estakadaimport');
        
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

        // Определяем тип изображения
        $imageInfo = @getimagesize($tempFile);
        if (!$imageInfo) {
            throw new \Exception("Файл не является изображением");
        }
        
        $imageType = $imageInfo[2];
        
        // Проверяем поддерживаемые форматы
        if ($imageType === IMAGETYPE_GIF) {
            unlink($tempFile);
            throw new \Exception("Формат GIF не поддерживается");
        }

        // Обрабатываем основное изображение (600x600) и конвертируем в JPG
        try {
            $image = new Image($tempFile);
            $image->resize(600, 600, true, Image::SCALE_OUTSIDE);
            $image->toFile($basePath . '/' . $fileName, IMAGETYPE_JPEG, ['quality' => 85]);
        } catch (\Exception $e) {
            throw new \Exception("Ошибка обработки основного изображения: " . $e->getMessage());
        }

        // Обрабатываем миниатюру (250x250) и конвертируем в JPG
        try {
            $thumb = new Image($tempFile);
            $thumb->resize(250, 250, true, Image::SCALE_OUTSIDE);
            
            // Имя для миниатюры: originalname_250x250.jpg
            $thumbName = pathinfo($fileName, PATHINFO_FILENAME) . '_250x250.jpg';
            $thumb->toFile($resizedPath . '/' . $thumbName, IMAGETYPE_JPEG, ['quality' => 85]);
        } catch (\Exception $e) {
            // Log::add('Ошибка создания миниатюры: ' . $e->getMessage(), Log::WARNING, 'com_estakadaimport');
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
        // Log::add(sprintf('Сохранение информации о медиафайле: %s', $fileName), Log::DEBUG, 'com_estakadaimport');
        
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
        
        // Log::add('Данные медиафайла: ' . json_encode($media), Log::DEBUG, 'com_estakadaimport');
        
        try {
            $result = $this->db->insertObject('#__virtuemart_medias', $media);
            $mediaId = $this->db->insertid();
            
            // Log::add(sprintf('Медиафайл сохранен с ID: %d', $mediaId), Log::DEBUG, 'com_estakadaimport');
            
            // Связываем медиафайл с товаром
            $productMedia = new \stdClass();
            $productMedia->virtuemart_product_id = $productId;
            $productMedia->virtuemart_media_id = $mediaId;
            
            // Log::add('Данные связи: ' . json_encode($productMedia), Log::DEBUG, 'com_estakadaimport');
            
            $this->db->insertObject('#__virtuemart_product_medias', $productMedia);
            
            // Log::add('Связь товара с медиафайлом сохранена', Log::DEBUG, 'com_estakadaimport');
            
            return $mediaId;
            
        } catch (\Exception $e) {
            // Log::add('Ошибка сохранения медиафайла: ' . $e->getMessage(), Log::ERROR, 'com_estakadaimport');
            throw $e;
        }
    }
}