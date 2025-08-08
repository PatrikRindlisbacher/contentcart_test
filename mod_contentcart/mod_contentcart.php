<?php
/**
 * Content Cart
 *
 * @version 	@version@
 * @author		Joomline
 * @copyright	(C) 2018 Efanych (efanych@gmail.com), Joomline. All rights reserved.
 * @license 	GNU General Public License version 2 or later; see	LICENSE.txt
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Helper\ModuleHelper;

defined('_JEXEC') or die;

if ($params->def('prepare_content', 1))
{
	Factory::getApplication()->bootPlugin('content', true);
	$module->content = HTMLHelper::_('content.prepare', $module->content, '', 'mod_contentcart.content');
}

$moduleclass_sfx = htmlspecialchars($params->get('moduleclass_sfx'), ENT_COMPAT, 'UTF-8');

require ModuleHelper::getLayoutPath('mod_contentcart', $params->get('layout', 'default'));
