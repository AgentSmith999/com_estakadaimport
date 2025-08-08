<?php

/**
 * Файл с формой и JS
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;

HTMLHelper::_('form.csrf');

/** @var \Joomla\Component\Estakadaimport\Site\View\Export\HtmlView $this */

// Подключаем assets
$this->document->getWebAssetManager()
    ->useScript('jquery')
    ->registerAndUseScript('com_estakadaimport.sheetjs', 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js');

// Данные из модели
$data = $this->data;
$profiles = $data['profiles'];
$selectedProfile = $data['selectedProfile'];

?>
<div class="com-estakadaimport-export">
    <h2>Экспорт товаров</h2>
    
    <div class="btn-group">
        <a href="<?php echo Route::_('index.php?option=com_estakadaimport&task=export.export&format=xml'); ?>" 
           class="btn btn-primary">
            Экспорт в XML
        </a>
        
        <a href="<?php echo Route::_('index.php?option=com_estakadaimport&task=export.export&format=table'); ?>" 
           class="btn btn-success">
            Экспорт в Excel
        </a>
    </div>
    
    <?php if (!empty($this->items)) : ?>
        <div class="export-preview mt-3">
            <h4>Будут экспортированы следующие товары:</h4>
            <ul>
                <?php foreach ($this->items as $item) : ?>
                    <li><?php echo $item->product_name; ?> (<?php echo $item->product_sku; ?>)</li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>