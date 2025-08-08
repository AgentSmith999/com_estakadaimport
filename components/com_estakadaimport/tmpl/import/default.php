<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;

HTMLHelper::_('form.csrf');

$app = Factory::getApplication();
?>
<div class="com-estakadaimport-import">
    <h2>Импорт товаров в VirtueMart</h2>
    
    <form action="<?php echo Route::_('index.php?option=com_estakadaimport&task=import'); ?>"
          method="post"
          enctype="multipart/form-data"
          class="form-horizontal">
          
        <div class="control-group">
            <label class="control-label" for="xlsfile">Файл Excel:</label>
            <div class="controls">
                <input type="file" name="xlsfile" id="xlsfile" accept=".xls,.xlsx" required />
            </div>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">
                Импортировать
            </button>
        </div>
    </form>
</div>
