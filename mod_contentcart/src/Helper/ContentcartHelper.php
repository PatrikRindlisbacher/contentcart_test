<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_contentcart
 *
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Joomline\Module\Contentcart\Site\Helper;

// phpcs:disable PSR1.Files.SideEffects

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * Helper for mod_contentcart
 *
 * @since  2.0.0
 */
final class ContentcartHelper
{
	/**
	 * Get cart data
	 *
	 * @param   Registry  $params  Module parameters
	 *
	 * @return  array
	 *
	 * @since   2.0.0
	 */
	public function getCartData(Registry $params): array
	{
		$app     = Factory::getApplication();
		$session = $app->getSession();

		// Get plugin parameters
		$plugin = PluginHelper::getPlugin('content', 'contentcart');

		// If plugin is not found or not enabled, return empty data
		if (!$plugin)
		{
			return [
				'content_order' => [],
				'total'         => 0.0,
				'item_count'    => 0,
				'cart_url'      => '',
				'currency'      => '',
				'using_price'   => 0,
			];
		}

		$pluginParams = new Registry($plugin->params ?? '');

		// Get cart content from session
		$content_order = $session->get('content_order', []);

		if (!is_array($content_order))
		{
			$content_order = [];
		}

		// Calculate totals
		$total      = 0.0;
		$item_count = count($content_order);

		foreach ($content_order as $item)
		{
			$price = isset($item['price']) ? (float) $item['price'] : 0.0;
			$count = isset($item['count']) ? (int) $item['count'] : 1;
			$total += $price * $count;
		}

		// Get cart URL
		$cart_url = $this->getCartUrl($pluginParams, $content_order);

		return [
			'content_order' => $content_order,
			'total'         => $total,
			'item_count'    => $item_count,
			'cart_url'      => $cart_url,
			'currency'      => $pluginParams->get('currency', ''),
			'using_price'   => $pluginParams->get('using_price', 0),
		];
	}

	/**
	 * Get cart URL
	 *
	 * @param   Registry  $pluginParams   Plugin parameters
	 * @param   array     $content_order  Cart content
	 *
	 * @return  string
	 *
	 * @since   2.0.0
	 */
	private function getCartUrl(Registry $pluginParams, array $content_order): string
	{
		$menuItem = $pluginParams->get('mymenuitem');

		if ($menuItem)
		{
			return Route::_("index.php?Itemid=" . $menuItem);
		}

		if (!empty($content_order[0]['link']))
		{
			try
			{
				$uri = Uri::getInstance($content_order[0]['link']);
				$uri->setVar('cart', '1');
				return $uri->toString();
			}
			catch (\Exception $e)
			{
				// Handle gracefully
			}
		}

		return '';
	}
}
