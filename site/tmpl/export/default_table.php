<?php
defined('_JEXEC') or die;

// Функция для форматирования изображений
function formatImages(array $images): string
{
    if (empty($images)) {
        return 'Нет изображения';
    }
    
    $baseUrl = 'https://estakada-market.ru/';
    $links = [];
    
    foreach ($images as $img) {
        // Удаляем начальный слэш, если есть (чтобы избежать дублирования)
        $cleanPath = ltrim($img, '/');
        $fullUrl = $baseUrl . $cleanPath;
        $fileName = basename($img);
        
        $links[] = '<a href="' . htmlspecialchars($fullUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank">' 
                 . htmlspecialchars($fileName, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    
    return implode('|', $links);
}
?>

<?php
if (!empty($this->items)) : ?>
<div class="table-scroll-container" id="dynamic-table-container">
<table border="1" cellpadding="5" cellspacing="0" style="width:100%" class="scrollable-table" id="export-preview-table">
    <thead>
        <tr>
            <?php 
            // Выводим фиксированные заголовки
            foreach ($this->fixed_headers as $header): ?>
                <th><?php echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8'); ?></th>
            <?php endforeach; ?>

            <!-- Универсальные кастомные поля -->
            <?php foreach ($this->universal_custom_titles as $title): ?>
                <th><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></th>
            <?php endforeach; ?>
            
            <!-- Обычные кастомные поля -->
            <?php foreach ($this->custom_titles as $title): ?>
                <th><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php if (!empty($this->product_ids)) : ?>
        <?php foreach ($this->product_ids as $product_id): ?>
        <tr>
            <!-- Категория -->
            <td><?php echo htmlspecialchars($this->product_categories[$product_id] ?? 'Без категории', ENT_QUOTES, 'UTF-8'); ?></td>
            <!--<td><?php // $category = $this->product_categories[$product_id] ?? []; echo htmlspecialchars($category['category_name'] ?? 'Без категории', ENT_QUOTES, 'UTF-8'); ?></td>-->
            
            <!-- Производитель -->
            <td><?php echo htmlspecialchars($this->product_manufacturers[$product_id] ?? 'Не указан', ENT_QUOTES, 'UTF-8'); ?></td>
            
            <!-- Артикул -->
            <td><?php 
                // Если $product_skus не передается, можно использовать product_id
                echo htmlspecialchars($this->product_skus[$product_id]['product_sku'] ?? $product_id, ENT_QUOTES, 'UTF-8'); 
            ?></td>
            
            <!-- Наименование -->
            <td><?php  echo htmlspecialchars($this->product_names[$product_id] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            
            <!-- Цена -->
            <td><?php echo htmlspecialchars($this->product_prices[$product_id]['base'] ?? '0', ENT_QUOTES, 'UTF-8'); ?></td>
            
            <!-- Модификатор цены -->
            <td><?php echo htmlspecialchars($this->product_prices[$product_id]['override'] ?? '0', ENT_QUOTES, 'UTF-8'); ?></td>

            <!-- Изображение -->
            <td><?php 
                $images = $this->product_images[$product_id] ?? [];
                echo formatImages($images);
            ?></td>
            
            <!-- Универсальные кастомные поля -->
            <?php foreach ($this->universal_custom_ids as $custom_id): ?>
                <td>
                    <?php 
                    if (isset($this->all_custom_values[$product_id][$custom_id])) {
                        echo htmlspecialchars($this->all_custom_values[$product_id][$custom_id], ENT_QUOTES, 'UTF-8');
                    }
                    ?>
                </td>
            <?php endforeach; ?>

            <!-- Обычные кастомные поля -->
            <?php foreach ($this->virtuemart_custom_ids as $custom_id): ?>
                <td>
                    <?php 
                    if (isset($this->all_custom_values[$product_id][$custom_id])) {
                        echo htmlspecialchars($this->all_custom_values[$product_id][$custom_id], ENT_QUOTES, 'UTF-8');
                    }
                    ?>
                </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
    <?php else : ?>
        <tr>
            <td colspan="<?php echo count($this->fixed_headers) + count($this->universal_custom_titles) + count($this->custom_titles); ?>" class="text-center">
                Нет данных для экспорта
            </td>
        </tr>
    <?php endif; ?>    
    </tbody>
</table>
</div>
<?php else : ?>
<p class="alert alert-info">Нет данных для экспорта.</p>
<?php endif; ?>