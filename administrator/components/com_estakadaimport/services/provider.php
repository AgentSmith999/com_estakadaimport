<?php
defined('_JEXEC') or die;

use Joomla\CMS\Extension\Service\Provider\HelperFactory;
use Joomla\CMS\Extension\Service\Provider\Module;
use Joomla\CMS\Extension\Service\Provider\Router;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Component\Estakadaimport\Site\Model\ExportModel;
use Joomla\Component\Estakadaimport\Site\Model\ImportModel;

class EstakadaimportServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        // Регистрация моделей
        $container->registerServiceProvider(new HelperFactory('\\Joomla\\Component\\Estakadaimport\\Site\\Helper'));
        
        $container->set(
            ExportModel::class,
            function (Container $container) {
                return new ExportModel();
            }
        );
        
        $container->set(
            ImportModel::class,
            function (Container $container) {
                return new ImportModel();
            }
        );
    }
}