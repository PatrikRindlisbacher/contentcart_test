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
$plugin = PluginHelper::getPlugin('content','contentcart');
$pluginParams = new Registry($plugin->params);
$session = Factory::getApplication()->getSession();
if($pluginParams->get('mymenuitem')){
	$cart_url = Route::_("index.php?Itemid=".$pluginParams->get('mymenuitem'));
} else {
	$order = $session->get('content_order');

	$uri = Uri::getInstance();
	$uri->parse( $order[0]['link'].'?cart=1');
	$uri->setVar('cart', '1');
	$cart_url = $uri->toString();
}
if ($session->get('content_order')) {
?>
<div class="content_cart jlcontentcart">
	<div class="content_cart_info" style="display:none;">
	<?php $i = 0; $total=0; foreach($session->get('content_order') as $order_item){
		$order_item['title'];/*Название товара*/
		$order_item['link'];/*Урл товара*/
		$order_item['price'];/*Цена товара, если есть*/
		$order_item['count'];/*количество товара*/
		$i++; $total=$total+($order_item['price']*$order_item['count']);}
	?>
	</div>
	<p class="count"><span><?php echo Text::_('CONTENTCART_PRODUCTS_COUNT')?>: </span><span><?php echo ' '.count($session->get('content_order')); ?> </span></p>
	<p class="total"><span><?php echo Text::_('CONTENTCART_PRODUCT_TOTAL')?>: </span><span><?php echo ' '.$total.' '.$pluginParams->get('currency'); ?> </span></p>
	<a class="jlcc-button jlcc-success" title="" href="<?php echo $cart_url ?>"><?php echo Text::_('CONTENTCART_GO_TO_CART')?></a>
</div>
<?php } else { ?>
<div class="content_cart jlcontentcart" >
	<p class="count"><span><?php echo Text::_('CONTENTCART_PRODUCTS_COUNT')?>: </span><span><?php echo ' 0 ' ?> </span></p>
	<p class="total"><span><?php echo Text::_('CONTENTCART_PRODUCT_TOTAL')?>: </span><span><?php echo ' 0 '.$pluginParams->get('currency'); ?> </span></p>
	<a onclick="return false" class="jlcc-button jlcc-primary" title="" href="<?php echo $cart_url ?>"><?php echo Text::_('CONTENTCART_EMPTY_CART')?></a>
</div>
<?php } ?>
