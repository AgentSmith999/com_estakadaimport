<?php
defined('_JEXEC') or die;
file_put_contents(JPATH_ROOT.'/debug_log.txt', print_r($_GET, true)."\n", FILE_APPEND);


// Получаем параметры
$profileId = JFactory::getApplication()->input->getInt('export_profile', 0);
// Получаем текущего пользователя
$user = JFactory::getUser();

// Проверяем авторизацию
if ($user->guest) {
    die('Доступ запрещен. Необходима авторизация.');
}

// Ваш существующий код для построения таблицы
$db = JFactory::getDbo();

// Получаем vendor_id для текущего пользователя
$queryVendor = $db->getQuery(true);
$queryVendor->select($db->quoteName('idVendora'))
           ->from($db->quoteName('#__virtuemart_userinfos'))
           ->where($db->quoteName('virtuemart_user_id') . ' = ' . (int)$user->id);

$db->setQuery($queryVendor);
$vendorId = $db->loadResult();

if (empty($vendorId)) {
    die('Пользователь не является продавцом.');
}

// 3. Получаем кастомные поля (где custom_parent_id = 23)
$queryCustoms = $db->getQuery(true);
$queryCustoms->select($db->quoteName(array('virtuemart_custom_id', 'custom_title')))
            ->from($db->quoteName('#__virtuemart_customs'))
            ->where($db->quoteName('custom_parent_id') . ' = ' . (int)$selectedProfile);

$db->setQuery($queryCustoms);
$customResults = $db->loadObjectList();

// Создаем массивы для кастомных полей
$virtuemart_custom_ids = array();
$custom_titles = array();

foreach ($customResults as $row) {
    $virtuemart_custom_ids[] = $row->virtuemart_custom_id;
    $custom_titles[] = $row->custom_title;
}

// 4. Получаем универсальные кастомные поля (custom_parent_id = 0 и field_type = 'S')
$queryUniversalCustoms = $db->getQuery(true);
$queryUniversalCustoms->select($db->quoteName(array('virtuemart_custom_id', 'custom_title')))
                     ->from($db->quoteName('#__virtuemart_customs'))
                     ->where($db->quoteName('custom_parent_id') . ' = 0')
                     ->where($db->quoteName('field_type') . ' IN (' . $db->quote('S') . ', ' . $db->quote('E') . ')'); // включаем и 'S', и 'E'

$db->setQuery($queryUniversalCustoms);
$universalCustomResults = $db->loadObjectList();

// Создаем массивы для кастомных полей
$virtuemart_custom_ids = array();
$custom_titles = array();

foreach ($customResults as $row) {
    $virtuemart_custom_ids[] = $row->virtuemart_custom_id;
    $custom_titles[] = $row->custom_title;
}

// Создаем массивы для универсальных кастомных полей
$universal_custom_ids = array();
$universal_custom_titles = array();

foreach ($universalCustomResults as $row) {
    $universal_custom_ids[] = $row->virtuemart_custom_id;
    $universal_custom_titles[] = $row->custom_title;
}

// 5. Получаем данные продуктов (для текущего продавца)
$queryProducts = $db->getQuery(true);
$queryProducts->select($db->quoteName(array('virtuemart_product_id', 'product_sku')))
             ->from($db->quoteName('#__virtuemart_products'))
             ->where($db->quoteName('virtuemart_vendor_id') . ' = ' . (int)$vendorId);

$db->setQuery($queryProducts);
$productResults = $db->loadObjectList();

// Создаем массивы для продуктов
$product_ids = array();
$product_skus = array();

foreach ($productResults as $product) {
    $product_ids[] = $product->virtuemart_product_id;
    $product_skus[] = $product->product_sku;
}

// 6. Получаем наименования товаров на русском
$queryNames = $db->getQuery(true);
$queryNames->select($db->quoteName(array('virtuemart_product_id', 'product_name')))
          ->from($db->quoteName('#__virtuemart_products_ru_ru'))
          ->where($db->quoteName('virtuemart_product_id') . ' IN (' . implode(',', $product_ids) . ')');

$db->setQuery($queryNames);
$nameResults = $db->loadObjectList();

// Создаем ассоциативный массив [product_id => product_name]
$product_names = array();
foreach ($nameResults as $name) {
    $product_names[$name->virtuemart_product_id] = $name->product_name;
}

// 7. Получаем категории для товаров (новый блок)
$queryCategories = $db->getQuery(true);
$queryCategories->select($db->quoteName(['pc.virtuemart_product_id', 'c.category_name']))
    ->from($db->quoteName('#__virtuemart_product_categories', 'pc'))
    ->join('LEFT', $db->quoteName('#__virtuemart_categories_ru_ru', 'c') . 
        ' ON ' . $db->quoteName('pc.virtuemart_category_id') . ' = ' . $db->quoteName('c.virtuemart_category_id'))
    ->where($db->quoteName('pc.virtuemart_product_id') . ' IN (' . implode(',', $product_ids) . ')');

$db->setQuery($queryCategories);
$categoryResults = $db->loadObjectList('virtuemart_product_id');

$product_categories = [];
foreach ($categoryResults as $productId => $row) {
    $product_categories[$productId] = $row->category_name ?? 'Без категории';
}

// 8. Получаем цены товаров
$queryPrices = $db->getQuery(true);
$queryPrices->select($db->quoteName([
            'virtuemart_product_id', 
            'product_price', 
            'product_override_price'
        ]))
        ->from($db->quoteName('#__virtuemart_product_prices'))
        ->where($db->quoteName('virtuemart_product_id') . ' IN (' . implode(',', $product_ids) . ')');

$db->setQuery($queryPrices);
$priceResults = $db->loadObjectList('virtuemart_product_id');

// Функция для форматирования цены
function formatPrice($priceValue) {
    if (empty($priceValue)) return '0';
    
    // Удаляем лишние нули и преобразуем в число
    $price = (float)preg_replace('/^0+/', '', $priceValue);
    
    // Проверяем, есть ли дробная часть
    if (floor($price) == $price) {
        return number_format($price, 0, '.', ' ');
    } else {
        return number_format($price, 2, '.', ' ');
    }
}

// Создаем массивы для цен
$product_prices = [];
$product_override_prices = [];

foreach ($priceResults as $productId => $row) {
    $product_prices[$productId] = formatPrice($row->product_price);
    $product_override_prices[$productId] = formatPrice($row->product_override_price);
}

// 9. Получаем производителей для товаров (добавляем этот запрос перед получением наименований)
$queryManufacturers = $db->getQuery(true);
$queryManufacturers->select($db->quoteName(['pm.virtuemart_product_id', 'mf.mf_name']))
    ->from($db->quoteName('#__virtuemart_product_manufacturers', 'pm'))
    ->join('LEFT', $db->quoteName('#__virtuemart_manufacturers_ru_ru', 'mf') . 
        ' ON ' . $db->quoteName('pm.virtuemart_manufacturer_id') . ' = ' . $db->quoteName('mf.virtuemart_manufacturer_id'))
    ->where($db->quoteName('pm.virtuemart_product_id') . ' IN (' . implode(',', $product_ids) . ')');

$db->setQuery($queryManufacturers);
$manufacturerResults = $db->loadObjectList('virtuemart_product_id');

// Создаем массив [product_id => manufacturer_name]
$product_manufacturers = array();
foreach ($manufacturerResults as $productId => $row) {
    $product_manufacturers[$productId] = $row->mf_name ?? 'Не указан';
}

// 10. Получаем изображения товаров
$queryImages = $db->getQuery(true);
$queryImages->select($db->quoteName([
            'pm.virtuemart_product_id', 
            'm.file_url'
        ]))
        ->from($db->quoteName('#__virtuemart_product_medias', 'pm'))
        ->join('INNER', $db->quoteName('#__virtuemart_medias', 'm') . 
              ' ON ' . $db->quoteName('pm.virtuemart_media_id') . ' = ' . $db->quoteName('m.virtuemart_media_id'))
        ->where($db->quoteName('pm.virtuemart_product_id') . ' IN (' . implode(',', $product_ids) . ')')
        ->order('pm.virtuemart_product_id, pm.ordering');

$db->setQuery($queryImages);
$imageResults = $db->loadObjectList();

// Группируем изображения по товару
$product_images = [];
foreach ($imageResults as $row) {
    if (!isset($product_images[$row->virtuemart_product_id])) {
        $product_images[$row->virtuemart_product_id] = [];
    }
    $product_images[$row->virtuemart_product_id][] = $row->file_url;
}

// Функция для форматирования списка изображений
function formatImages($images) {
    if (empty($images)) return 'Нет изображения';
        // Добавляем домен и убираем пробелы вокруг разделителя
    $baseUrl = 'https://estakada-market.ru/';
    $formatted = [];
    
    foreach ($images as $img) {
        // Удаляем начальный слэш, если есть (чтобы избежать дублирования)
        $cleanPath = ltrim($img, '/');
        $fullUrl = $baseUrl . $cleanPath;
        $links[] = '<a href="' . htmlspecialchars($fullUrl) . '" target="_blank">' 
                 . htmlspecialchars(basename($img)) . '</a>';
    }
    
    return implode('|', $links);
}

// 11.1 Получаем ID всех кастомных полей типа 'E' (плагинные)
$queryCustomFieldsE = $db->getQuery(true);
$queryCustomFieldsE->select($db->quoteName('virtuemart_custom_id'))
                  ->from($db->quoteName('#__virtuemart_customs'))
                  ->where($db->quoteName('field_type') . ' = ' . $db->quote('E'));

$db->setQuery($queryCustomFieldsE);
$customFieldsE = $db->loadColumn(); // массив ID полей типа E

// 11.2 Получаем значения всех кастомных полей (обычных + универсальных) для товаров
$all_custom_ids = array_merge($universal_custom_ids, $virtuemart_custom_ids);

if (!empty($all_custom_ids)) {
    $queryAllCustomValues = $db->getQuery(true);
    $queryAllCustomValues->select($db->quoteName(array('virtuemart_product_id', 'virtuemart_custom_id', 'customfield_value')))
                        ->from($db->quoteName('#__virtuemart_product_customfields'))
                        ->where($db->quoteName('virtuemart_product_id') . ' IN (' . implode(',', $product_ids) . ')')
                        ->where($db->quoteName('virtuemart_custom_id') . ' IN (' . implode(',', $all_custom_ids) . ')')
                        ->order('virtuemart_product_id, virtuemart_custom_id'); // сортируем для удобства обработки

    $db->setQuery($queryAllCustomValues);
    $allCustomValuesResults = $db->loadObjectList();

    // Создаем единый массив для всех значений кастомных полей
    $all_custom_values = array();
    
    foreach ($allCustomValuesResults as $value) {
        $product_id = $value->virtuemart_product_id;
        $custom_id = $value->virtuemart_custom_id;
        $field_value = $value->customfield_value;

        if (!isset($all_custom_values[$product_id])) {
            $all_custom_values[$product_id] = array();
        }

        // Если поле типа E (множественное значение)
        if (in_array($custom_id, $customFieldsE)) {
            if (!isset($all_custom_values[$product_id][$custom_id])) {
                $all_custom_values[$product_id][$custom_id] = array();
            }
            $all_custom_values[$product_id][$custom_id][] = $field_value;
        } 
        // Обычное поле (одиночное значение)
        else {
            $all_custom_values[$product_id][$custom_id] = $field_value;
        }
    }

    // Объединяем множественные значения через запятую (для полей типа E)
    foreach ($all_custom_values as &$product_values) {
        foreach ($product_values as $custom_id => &$value) {
            if (in_array($custom_id, $customFieldsE) && is_array($value)) {
                $value = implode('|', $value); // или другой разделитель
            }
        }
    }
    unset($product_values, $value); // сбрасываем ссылки
} else {
    $all_custom_values = array();
}

// 12. Дополнительные фиксированные заголовки
$fixed_headers = array(
//    'virtuemart_product_id',
    'Категория (Номер/ID/Название)',
    'Производитель',
    'Артикул',
    'Наименование товара',
    'Цена',
    'Модификатор цены',
    'Изображение'
);
?>
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
