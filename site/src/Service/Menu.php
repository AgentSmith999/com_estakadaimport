<?php
defined('_JEXEC') or die;

use Joomla\CMS\Menu\MenuItem;

class Menu
{
    public static function addItems(MenuItem $menu)
    {
        $component = \Joomla\CMS\Factory::getApplication()
            ->bootComponent('com_estakadaimport');

        if (method_exists($component, 'getMenuItems')) {
            foreach ($component->getMenuItems() as $key => $item) {
                $menu->addChild(new MenuItem([
                    'title' => $item['title'],
                    'link' => $item['link'],
                    'component_id' => $component->getComponentId()
                ]));
            }
        }
    }
}