<?php
defined('_JEXEC') or die;

use Joomla\CMS\Component\Router\RouterBase;

class EstakadaimportRouter extends RouterBase
{
    public function build(&$query)
    {
        $segments = [];
        
        if (isset($query['view'])) {
            $segments[] = $query['view'];
            unset($query['view']);
        }
        
        return $segments;
    }

    public function parse(&$segments)
    {
        $vars = [];
        
        if (isset($segments[0])) {
            $vars['view'] = $segments[0];
        }
        
        return $vars;
    }
}