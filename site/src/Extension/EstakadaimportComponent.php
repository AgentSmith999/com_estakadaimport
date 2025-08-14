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

class EstakadaimportComponent extends Component implements 
    BootableExtensionInterface,
    CategoryServiceInterface,
    RouterServiceInterface
{
    use CategoryServiceTrait;
    use RouterServiceTrait;
    use HTMLRegistryAwareTrait;

    public function boot(ContainerInterface $container)
    {
        // Регистрируем представления для меню
        $this->registerView('import', 'site');
        $this->registerView('export', 'site');

        // Явно регистрируем виды для меню
        $this->getRouterFactory()->registerView('import');
        $this->getRouterFactory()->registerView('export');
    }
}