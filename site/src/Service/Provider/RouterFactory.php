<?php
defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

class RouterFactory implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        $container->set(
            RouterInterface::class,
            function (Container $container) {
                return new EstakadaimportRouter();
            }
        );
    }
}