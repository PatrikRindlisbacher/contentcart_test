<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_contentcart
 *
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Joomline\Module\Contentcart\Site\Dispatcher;

// phpcs:disable PSR1.Files.SideEffects

defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Joomla\CMS\Helper\HelperFactoryAwareInterface;
use Joomla\CMS\Helper\HelperFactoryAwareTrait;

/**
 * Dispatcher class for mod_contentcart
 *
 * @since  2.0.0
 */
final class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
	use HelperFactoryAwareTrait;

	/**
	 * Returns the layout data.
	 *
	 * @return  array
	 *
	 * @since   2.0.0
	 */
	protected function getLayoutData(): array
	{
		$data = parent::getLayoutData();

		// Get cart data through Helper
		$data['cartData'] = $this->getHelperFactory()
			->getHelper('ContentcartHelper')
			->getCartData($data['params']);

		return $data;
	}
}
