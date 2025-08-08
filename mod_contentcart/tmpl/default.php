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
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;


defined('_JEXEC') or die;
$doc = Factory::getDocument();
if ($params->get('enable_css', 1))
{
	$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
	$wa->registerAndUseStyle('plg_content_contentcart.front', 'plugins/content/contentcart/assets/css/jlcontentcart.css');
}
$plugin = PluginHelper::getPlugin('content', 'contentcart');
$pluginParams = new Registry($plugin->params ?? '');
$session = Factory::getApplication()->getSession();
$content_order = $session->get('content_order', []);

if (!is_array($content_order))
{
	$content_order = [];
}

$cart_url = '';
$menuItem = $pluginParams->get('mymenuitem');

if ($menuItem)
{
	$cart_url = Route::_("index.php?Itemid=" . $menuItem);
}
elseif (!empty($content_order[0]['link']))
{
	try
	{
		$uri = Uri::getInstance($content_order[0]['link']);
		$uri->setVar('cart', '1');
		$cart_url = $uri->toString();
	}
	catch (\Exception $e)
	{
		$cart_url = ''; // Handle gracefully
	}
}

$total = 0;
$item_count = count($content_order);

if ($item_count > 0)
{
	foreach ($content_order as $order_item)
	{
		$price = isset($order_item['price']) ? (float) $order_item['price'] : 0;
		$count = isset($order_item['count']) ? (int) $order_item['count'] : 1;
		$total += $price * $count;
	}
}

$currency = htmlspecialchars($pluginParams->get('currency', ''), ENT_QUOTES, 'UTF-8');
?>

<div class="content_cart jlcontentcart">
    <p class="count">
        <span><?php echo Text::_('MOD_CONTENTCART_PRODUCTS_COUNT'); ?>: </span>
        <span><?php echo $item_count; ?></span>
    </p>
	<?php if ($pluginParams->get('using_price') == '1') : ?>
        <p class="total">
            <span><?php echo Text::_('MOD_CONTENTCART_PRODUCT_TOTAL'); ?>: </span>
            <span><?php echo $total . ' ' . $currency; ?></span>
        </p>
	<?php endif; ?>

	<?php if ($item_count > 0 && $cart_url) : ?>
        <a class="jlcc-button jlcc-success" href="<?php echo htmlspecialchars($cart_url, ENT_QUOTES, 'UTF-8'); ?>">
			<?php echo Text::_('MOD_CONTENTCART_GO_TO_CART'); ?>
        </a>
	<?php else : ?>
        <a class="jlcc-button jlcc-primary" href="#" disabled>
			<?php echo Text::_('MOD_CONTENTCART_EMPTY_CART'); ?>
        </a>
	<?php endif; ?>
</div>
