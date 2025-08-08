<?php
defined('_JEXEC') or die;

use Joomla\CMS\Router\Route;

// Просто перенаправляем на exxml.php
$redirectUrl = Route::_('index.php?option=com_estakadaimport&view=export&layout=exxml');
$this->app->redirect($redirectUrl);
?>