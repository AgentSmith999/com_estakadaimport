<?php
defined('_JEXEC') or die;

namespace Joomla\Component\Estakadaimport\Site\Service\Provider;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\HTML\Registry;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Component\Estakadaimport\Site\Extension\EstakadaimportComponent;

class ComponentServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Joomla\\Component\\Estakadaimport'));
        $container->registerServiceProvider(new RouterFactory('\\Joomla\\Component\\Estakadaimport'));

        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new EstakadaimportComponent(
                    $container->get(ComponentDispatcherFactoryInterface::class)
                );

                $component->setRegistry($container->get(Registry::class));
                return $component;
            }
        );
    }
}