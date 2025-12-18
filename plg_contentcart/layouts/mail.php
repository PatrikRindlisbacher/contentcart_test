<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.contentcart
 *
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * Layout variables
 * @var Registry $params
 * @var array    $content_order
 * @var string   $client_name
 * @var string   $client_email
 * @var string   $client_phone
 * @var string   $client_note
 * @var string   $title_note
 */

extract($displayData);
?>
<h2><?php echo Text::_('PLG_CONTENT_CONTENTCART_ORDER_INFO'); ?></h2>
<table style="width:100%;">
	<?php if ($client_name) : ?>
		<tr><td><?php echo Text::_('PLG_CONTENT_CONTENTCART_CLIENT_NAME'); ?></td><td><?php echo htmlspecialchars($client_name, ENT_QUOTES, 'UTF-8'); ?></td></tr>
	<?php endif; ?>
	<?php if ($client_email) : ?>
		<tr><td><?php echo Text::_('PLG_CONTENT_CONTENTCART_CLIENT_EMAIL'); ?></td><td><?php echo htmlspecialchars($client_email, ENT_QUOTES, 'UTF-8'); ?></td></tr>
	<?php endif; ?>
	<?php if ($client_phone) : ?>
		<tr><td><?php echo Text::_('PLG_CONTENT_CONTENTCART_CLIENT_PHONE'); ?></td><td><?php echo htmlspecialchars($client_phone, ENT_QUOTES, 'UTF-8'); ?></td></tr>
	<?php endif; ?>
	<?php if ($client_note) : ?>
		<tr><td><?php echo htmlspecialchars($title_note, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo nl2br(htmlspecialchars($client_note, ENT_QUOTES, 'UTF-8')); ?></td></tr>
	<?php endif; ?>
</table>
<table style="width:100%;">
	<thead>
	<tr>
		<th>№</th>
		<th><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_TITLE'); ?></th>
		<th><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_COUNT'); ?></th>
		<?php if ($params->get('using_price') == '1') : ?>
			<th><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_PRICE'); ?></th>
			<th><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_SUMM'); ?></th>
		<?php endif; ?>
	</tr>
	</thead>
	<tbody>
	<?php
	$total = 0;
	foreach ($content_order as $i => $order_item) :
		$count = isset($order_item['count']) ? (int) $order_item['count'] : 1;
		$price = isset($order_item['price']) ? (float) $order_item['price'] : 0;
		$sum   = $price * $count;
		$total += $sum;
		?>
		<tr>
			<td><?php echo $i + 1; ?></td>
			<td><a class="order_item_name" href="<?php echo Uri::root() . ltrim($order_item['link'], '/'); ?>"><?php echo htmlspecialchars($order_item['title'], ENT_QUOTES, 'UTF-8'); ?></a></td>
			<td><?php echo $count; ?></td>
			<?php if ($params->get('using_price') == '1') : ?>
				<td><?php echo htmlspecialchars($price . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
				<td><?php echo htmlspecialchars($sum . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
			<?php endif; ?>
		</tr>
	<?php endforeach; ?>
	<?php if ($params->get('using_price') == '1') : ?>
	<tr>
		<td colspan="4" style="text-align:right;"><b><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_TOTAL'); ?>:&nbsp;</b></td>
		<td><?php echo htmlspecialchars($total . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
	</tr>
	<?php endif; ?>
	</tbody>
</table>
