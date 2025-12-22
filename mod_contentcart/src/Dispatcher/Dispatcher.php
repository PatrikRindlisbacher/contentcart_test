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
use Joomla\CMS\Factory;

/**
 * Dispatcher class for mod_contentcart
 *
 * @since  2.0.0
 */
final class Dispatcher extends AbstractModuleDispatcher implements HelperFactoryAwareInterface
{
	use HelperFactoryAwareTrait;

	/**
	 * Dispatches the module
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	public function dispatch()
	{
		// Load ContentCart assets (CSS and JS) - ensure they're available even if plugin doesn't load them
		$this->loadAssets();

		parent::dispatch();
	}

	/**
	 * Load ContentCart CSS and JavaScript assets
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	private function loadAssets(): void
	{
		try
		{
			$app = Factory::getApplication();
			$doc = $app->getDocument();
			$wa = $doc->getWebAssetManager();

			// Register and load ContentCart assets from plugin
			$wa->getRegistry()->addRegistryFile('plg_content_contentcart/joomla.asset.json');

			// Load CSS
			if (!$wa->assetExists('style', 'plg_content_contentcart.jlcontentcart'))
			{
				$wa->registerAndUseStyle('plg_content_contentcart.jlcontentcart', 'plg_content_contentcart/jlcontentcart.css');
			}
			else
			{
				$wa->useStyle('plg_content_contentcart.jlcontentcart');
			}

			// Load JavaScript
			if (!$wa->assetExists('script', 'plg_content_contentcart.contentcart'))
			{
				$wa->registerAndUseScript('plg_content_contentcart.contentcart', 'plg_content_contentcart/contentcart.js', [], ['defer' => true]);
			}
			else
			{
				$wa->useScript('plg_content_contentcart.contentcart');
			}

			if (!$wa->assetExists('script', 'plg_content_contentcart.contentcart-init'))
			{
				$wa->registerAndUseScript(
					'plg_content_contentcart.contentcart-init',
					'plg_content_contentcart/contentcart-init.js',
					[],
					['defer' => true],
					['plg_content_contentcart.contentcart']
				);
			}
			else
			{
				$wa->useScript('plg_content_contentcart.contentcart-init');
			}

			// Pass ContentCartOptions to JavaScript if not already set by plugin
			$this->loadContentCartOptions($doc);
		}
		catch (\Exception $e)
		{
			// Silently fail if assets can't be loaded - plugin might not be installed
			if (Factory::getApplication()->get('debug'))
			{
				Factory::getApplication()->enqueueMessage('ContentCart Module: Could not load assets - ' . $e->getMessage(), 'warning');
			}
		}
	}

	/**
	 * Load ContentCartOptions for JavaScript
	 *
	 * @param   object  $doc  Document object
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	private function loadContentCartOptions($doc): void
	{
		// Check if options already set by plugin
		$scriptOptions = $doc->getScriptOptions();
		if (isset($scriptOptions['ContentCartOptions']))
		{
			return; // Plugin already loaded options
		}

		// Load plugin params to get configuration
		$plugin = \Joomla\CMS\Plugin\PluginHelper::getPlugin('content', 'contentcart');
		if (!$plugin)
		{
			return; // Plugin not installed
		}

		$params = new \Joomla\Registry\Registry($plugin->params);

		// Pass options to JavaScript
		$doc->addScriptOptions('ContentCartOptions', [
			'apiUrl'    => \Joomla\CMS\Uri\Uri::root() . 'index.php?option=com_ajax&plugin=contentcart&group=content&format=json',
			'token'     => \Joomla\CMS\Session\Session::getFormToken(),
			'currency'  => $params->get('currency', 'RUB'),
			'usingPrice' => (int) $params->get('using_price', 0),
		]);
	}

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
