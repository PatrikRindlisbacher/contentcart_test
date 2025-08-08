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
use Joomla\CMS\Helper\ModuleHelper;

// No direct access
defined('_JEXEC') or die;

/**
 * @var  Joomla\CMS\Core\CMSApplicationInterface $app
 * @var  Joomla\CMS\Form\Form                  $params
 * @var  stdClass                              $module
 */

$app = Factory::getApplication();


$moduleclass_sfx = htmlspecialchars($params->get('moduleclass_sfx', ''), ENT_COMPAT, 'UTF-8');

require ModuleHelper::getLayoutPath('mod_contentcart', $params->get('layout', 'default'));
