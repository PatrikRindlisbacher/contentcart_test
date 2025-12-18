<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.contentcart
 *
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomline\Plugin\Content\Contentcart\Extension\Contentcart;

return new class implements ServiceProviderInterface
{
	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	public function register(Container $container): void
	{
		$container->set(
			PluginInterface::class,
			function (Container $container) {
				$dispatcher = $container->get(DispatcherInterface::class);
				$plugin = new Contentcart(
					$dispatcher,
					(array) PluginHelper::getPlugin('content', 'contentcart')
				);

				$plugin->setApplication(Factory::getApplication());

				// Register WebAsset file at plugin initialization
				try
				{
					$wa = $container->get(WebAssetRegistry::class);
					$wa->addRegistryFile('media/plg_content_contentcart/joomla.asset.json');
				}
				catch (\Exception $e)
				{
					// Silently fail if registry not available yet
				}

				return $plugin;
			}
		);
	}
};
