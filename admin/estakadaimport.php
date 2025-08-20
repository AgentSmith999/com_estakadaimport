<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactory;

class EstakadaimportComponent extends MVCFactory
{
    public function __construct()
    {
        parent::__construct('Estakadaimport');
    }
}