<?php
defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class implements ServiceProviderInterface {
    public function register(Container $container)
    {
        $container->registerServiceProvider(new MVCFactory('\\Joomla\\Component\\Estakadaimport'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Joomla\\Component\\Estakadaimport'));
        
        $container->set(
            ComponentInterface::class,
            function (Container $container) {
                $component = new \Joomla\Component\Estakadaimport\Administrator\Extension\EstakadaimportComponent(
                    $container->get(ComponentDispatcherFactoryInterface::class)
                );
                $component->setMVCFactory($container->get(MVCFactoryInterface::class));
                return $component;
            }
        );
        
        // Регистрация моделей
        $container->set(
            'EstakadaimportModelImport',
            function (Container $container) {
                return new \Joomla\Component\Estakadaimport\Site\Model\ImportModel(
                    $container->get(MVCFactoryInterface::class)
                );
            }
        );
    }
};