<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

$app = Factory::getApplication();
$component = $app->bootComponent('com_estakadaimport');

// Получаем контроллер через MVC Factory
$controller = $component->getMVCFactory()
    ->createController(
        'Display',
        'Site',
        [],
        $app,
        $app->input
    );

$controller->execute($app->input->getCmd('task', 'display'));
$controller->redirect();