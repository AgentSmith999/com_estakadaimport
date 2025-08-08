<?php
defined('_JEXEC') or die;

$model = $this->getModel();
$products = $model->getItems();
?>
<table border="1">
    <thead>
        <tr>
            <th>Артикул</th>
            <th>Наименование</th>
            <th>Количество</th>
            <th>Цена</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($products as $product) : ?>
        <tr>
            <td><?php echo $product->product_sku; ?></td>
            <td><?php echo $product->product_name; ?></td>
            <td><?php echo $product->product_in_stock; ?></td>
            <td><?php echo $product->product_price; ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>