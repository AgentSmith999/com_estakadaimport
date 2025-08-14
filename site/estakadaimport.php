<?php
defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Factory;

// Регистрация компонента
Factory::getApplication()->bootComponent('com_estakadaimport');