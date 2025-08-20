<?php
defined('_JEXEC') or die;

class EstakadaimportHelper
{
    public static function addSubmenu($vName)
    {
        JHtmlSidebar::addEntry(
            JText::_('COM_ESTAKADAIMPORT_SUBMENU_IMPORT'),
            'index.php?option=com_estakadaimport&view=import',
            $vName == 'import'
        );

        JHtmlSidebar::addEntry(
            JText::_('COM_ESTAKADAIMPORT_SUBMENU_EXPORT'),
            'index.php?option=com_estakadaimport&view=export',
            $vName == 'export'
        );
    }
}