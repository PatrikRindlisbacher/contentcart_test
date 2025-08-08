<?php
defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
?>
<?php
// Extracted from the layout data
$params = $displayData['params'];
$content_order = $displayData['content_order'];
$client_name = $displayData['client_name'];
$client_email = $displayData['client_email'];
$client_phone = $displayData['client_phone'];
$client_note = $displayData['client_note'];
$title_note = $displayData['title_note'];
$input = $displayData['input'];
?>
<h2><?php echo Text::_('CONTENTCART_ORDER_INFO'); ?></h2>
<table style="width:100%;">
	<?php if ($client_name) : ?>
		<tr><td><?php echo Text::_('CONTENTCART_CLIENT_NAME'); ?></td><td><?php echo htmlspecialchars($client_name, ENT_QUOTES, 'UTF-8'); ?></td></tr>
	<?php endif; ?>
	<?php if ($client_email) : ?>
		<tr><td><?php echo Text::_('CONTENTCART_CLIENT_EMAIL'); ?></td><td><?php echo htmlspecialchars($client_email, ENT_QUOTES, 'UTF-8'); ?></td></tr>
	<?php endif; ?>
	<?php if ($client_phone) : ?>
		<tr><td><?php echo Text::_('CONTENTCART_CLIENT_PHONE'); ?></td><td><?php echo htmlspecialchars($client_phone, ENT_QUOTES, 'UTF-8'); ?></td></tr>
	<?php endif; ?>
	<?php if ($client_note) : ?>
		<tr><td><?php echo htmlspecialchars($title_note, ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($client_note, ENT_QUOTES, 'UTF-8'); ?></td></tr>
	<?php endif; ?>
</table>
<table style="width:100%;">
	<thead>
		<tr>
			<td>№</td>
			<td><?php echo Text::_('CONTENTCART_PRODUCT_TITLE'); ?></td>
			<td><?php echo Text::_('CONTENTCART_PRODUCT_COUNT'); ?></td>
			<?php if ($params->get('using_price') == '1') : ?>
				<td><?php echo Text::_('CONTENTCART_PRODUCT_PRICE'); ?></td>
				<td><?php echo Text::_('CONTENTCART_PRODUCT_SUMM'); ?></td>
			<?php endif; ?>
		</tr>
	</thead>
	<tbody>
	<?php
	$total = 0;
	foreach ($content_order as $i => $order_item) :
		$count = $input->getInt('count' . $i, 1);
		$total += ($order_item['price'] * $count);
	?>
		<tr>
			<td><?php echo $i + 1; ?></td>
			<td><a class="order_item_name" href="<?php echo Uri::root() . $order_item['link']; ?>"><?php echo htmlspecialchars($order_item['title'], ENT_QUOTES, 'UTF-8'); ?></a></td>
			<td><?php echo (int) $count; ?></td>
			<?php if ($params->get('using_price') == '1') : ?>
				<td><?php echo htmlspecialchars($order_item['price'] . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
				<td><?php echo htmlspecialchars($order_item['price'] * $count . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
			<?php endif; ?>
		</tr>
	<?php endforeach; ?>
	<tr>
		<td colspan="4" style="text-align:right;"><b><?php echo Text::_('CONTENTCART_PRODUCT_TOTAL'); ?>:&nbsp;</b></td>
		<td><?php echo htmlspecialchars($total . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
	</tr>
	</tbody>
</table>