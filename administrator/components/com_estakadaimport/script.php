<?php
defined('_JEXEC') or die;

class Com_EstakadaimportInstallerScript
{
    public function install($parent)
    {
        JFactory::getApplication()->enqueueMessage('Компонент успешно установлен!');
    }

    public function uninstall($parent)
    {
        JFactory::getApplication()->enqueueMessage('Компонент удалён.');
    }
}