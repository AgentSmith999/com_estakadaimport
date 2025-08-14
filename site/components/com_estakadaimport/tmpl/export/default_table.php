<?php
defined('_JEXEC') or die;

if (!empty($items)) : ?>
<div class="table-scroll-container" id="dynamic-table-container">
<table border="1" cellpadding="5" cellspacing="0" style="width:100%" class="scrollable-table">
    <thead>
        <tr>
            <?php 
            // Выводим фиксированные заголовки
            foreach ($fixed_headers as $header): ?>
                <th><?php echo htmlspecialchars($header, ENT_QUOTES, 'UTF-8'); ?></th>
            <?php endforeach; ?>

            <!-- Выводим заголовки из универсальных кастомных полей -->
            <?php foreach ($universal_custom_titles as $title): ?>
                <th><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></th>
            <?php endforeach; ?>
            
            <!-- Выводим заголовки из кастомных полей -->
            <?php foreach ($custom_titles as $title): ?>
                <th><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($product_ids as $index => $product_id): ?>
        <tr>
            <!-- Заполняем virtuemart_product_id -->
            <!--<td><?php // echo htmlspecialchars($product_id, ENT_QUOTES, 'UTF-8'); ?></td>-->
            
            <!-- Заполняем Категорию -->
            <td><?php echo htmlspecialchars($product_categories[$product_id] ?? 'Без категории', ENT_QUOTES, 'UTF-8'); ?></td>
            
            <!-- Заполняем Производителя -->
            <td><?php echo htmlspecialchars($product_manufacturers[$product_id] ?? 'Не указан', ENT_QUOTES, 'UTF-8'); ?></td>
            
            <!-- Заполняем Артикул (product_sku) -->
            <td><?php echo htmlspecialchars($product_skus[$index] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            
            <!-- Заполняем Наименование товара -->
            <td><?php echo htmlspecialchars($product_names[$product_id] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            
            <!-- Цена -->
            <td><?php echo htmlspecialchars($product_prices[$product_id] ?? '0', ENT_QUOTES, 'UTF-8'); ?></td>
            
            <!-- Модификатор цены -->
            <td><?php echo htmlspecialchars($product_override_prices[$product_id] ?? '0', ENT_QUOTES, 'UTF-8'); ?></td>

            <!-- Изображение -->
            <td>
                <?php 
                $images = $product_images[$product_id] ?? [];
                echo formatImages($images);
                ?>
            </td>
            
            <!-- Выводим значения универсальных кастомных полей -->
            <?php foreach ($universal_custom_ids as $custom_id): ?>
                <td>
                    <?php 
                    if (isset($all_custom_values[$product_id][$custom_id])) {
                        echo htmlspecialchars($all_custom_values[$product_id][$custom_id], ENT_QUOTES, 'UTF-8');
                    }
                    ?>
                </td>
            <?php endforeach; ?>

            <!-- Выводим значения обычных кастомных полей -->
            <?php foreach ($virtuemart_custom_ids as $custom_id): ?>
                <td>
                    <?php 
                    if (isset($all_custom_values[$product_id][$custom_id])) {
                        echo htmlspecialchars($all_custom_values[$product_id][$custom_id], ENT_QUOTES, 'UTF-8');
                    }
                    ?>
                </td>
            <?php endforeach; ?>
            <!-- Конец вывода значения универсальных кастомных полей -->

        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php else : ?>
<p>Нет данных для экспорта.</p>
<?php endif; ?>