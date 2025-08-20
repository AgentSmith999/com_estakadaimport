<?php
defined('_JEXEC') or die;

use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\CMS\Categories\CategoryServiceTrait;
use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\BootableExtensionInterface;
use Joomla\CMS\Extension\Component;
use Joomla\CMS\HTML\HTMLRegistryAwareTrait;
use Psr\Container\ContainerInterface;

class MenuComponent extends Component implements 
    BootableExtensionInterface,
    CategoryServiceInterface,
    RouterServiceInterface
{
    use CategoryServiceTrait;
    use RouterServiceTrait;
    use HTMLRegistryAwareTrait;

    public function boot(ContainerInterface $container)
    {
        // Регистрируем представления для системы меню
        $this->registerView('import', 'site', ['link' => 'index.php?option=com_estakadaimport&view=import']);
        $this->registerView('export', 'site', ['link' => 'index.php?option=com_estakadaimport&view=export']);
    }
}