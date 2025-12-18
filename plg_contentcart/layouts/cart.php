<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.contentcart
 *
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * Layout variables
 * @var array    $displayData   Display data array
 * @var array    $content_order Cart content
 * @var Registry $params        Plugin parameters
 * @var object   $session       Session object
 */

// Extract variables from displayData
$content_order = $displayData['content_order'] ?? [];
$params        = $displayData['params'] ?? null;
$session       = $displayData['session'] ?? null;

// Safety check
if (!$params || !$session) {
	return;
}

$app     = Factory::getApplication();
$user    = $app->getIdentity();
$isGuest = $user->guest;

// Sanitize content order just in case
if (!is_array($content_order))
{
	$content_order = [];
}

// Update counts if form is submitted for that purpose
if (!$app->input->getInt('mail') && $app->isClient('site') && count($content_order) > 0)
{
	$counts = $app->input->get('count', [], 'array');

	if (!empty($counts))
	{
		$changed = false;
		foreach ($content_order as $key => &$item)
		{
			if (isset($counts[$key]))
			{
				$newCount = (int) $counts[$key];
				if ($newCount > 0 && $newCount <= 999 && $item['count'] != $newCount)
				{
					$item['count'] = $newCount;
					$changed = true;
				}
			}
		}

		if ($changed)
		{
			$session->set('content_order', $content_order);
		}
	}
}

// Safe user data
$username  = !$isGuest ? htmlspecialchars($user->name, ENT_QUOTES, 'UTF-8') : '';
$useremail = !$isGuest ? htmlspecialchars($user->email, ENT_QUOTES, 'UTF-8') : '';

// Load CSS via WebAssetManager
if ($params->get('enable_css', 1))
{
	$wa = Factory::getApplication()->getDocument()->getWebAssetManager();

	// Try to use plugin's CSS asset if available
	try
	{
		if ($wa->assetExists('style', 'plg_content_contentcart.jlcontentcart'))
		{
			$wa->useStyle('plg_content_contentcart.jlcontentcart');
		}
	}
	catch (\Exception $e)
	{
		// Asset not available, silently continue
	}
}
?>
<div class="jlcontentcart">
	<h1 class="title"><?php echo Text::_('PLG_CONTENT_CONTENTCART_SHOPPING_CART'); ?></h1>

	<?php if (empty($content_order)) : ?>
		<p><?php echo Text::_('PLG_CONTENT_CONTENTCART_CART_IS_EMPTY'); ?></p>
	<?php else : ?>
		<form name="cart_update" class="order_update" method="post" action="<?php echo htmlspecialchars(Uri::getInstance()->toString(), ENT_QUOTES, 'UTF-8'); ?>">
			<table style="width:100%;">
				<thead>
					<th>№</th>
					<th><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_TITLE'); ?></th>
					<th><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_COUNT'); ?></th>
					<?php if ($params->get('using_price') == '1') : ?>
						<th><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_PRICE'); ?></th>
						<th><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_SUMM'); ?></th>
					<?php endif; ?>
					<th></th>
				</thead>
				<tbody>
				<?php
				$total = 0;
				foreach ($content_order as $key => $order_item) :
					$itemPrice = isset($order_item['price']) ? (float) $order_item['price'] : 0;
					$itemCount = isset($order_item['count']) ? (int) $order_item['count'] : 1;
					$itemSum = $itemPrice * $itemCount;
					$total += $itemSum;
					?>
					<tr class="order_item">
						<td><?php echo $key + 1; ?></td>
						<td><a class="order_item_name" href="<?php echo htmlspecialchars($order_item['link'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($order_item['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?></a></td>
						<td><input class="jlcc-input jlcc-count" type="number" name="count[<?php echo $key; ?>]" max="999" min="1" value="<?php echo $itemCount; ?>" onchange="this.form.submit()"/></td>
						<?php if ($params->get('using_price') == '1') : ?>
							<td name="price"><?php echo htmlspecialchars($itemPrice . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
							<td><?php echo htmlspecialchars($itemSum . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
						<?php endif; ?>
						<td><a href="<?php echo htmlspecialchars(Uri::getInstance()->toString() . '?delete=' . $key . '&' . Session::getFormToken() . '=1', ENT_QUOTES, 'UTF-8'); ?>"><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_DELETE'); ?></a></td>
					</tr>
				<?php endforeach; ?>
				<?php if ($params->get('using_price') == '1') : ?>
					<tr class="order_total">
						<td colspan="4" style="text-align:right;"><b><?php echo Text::_('PLG_CONTENT_CONTENTCART_PRODUCT_TOTAL'); ?>:&nbsp;</b></td>
						<td> <?php echo htmlspecialchars($total . ' ' . $params->get('currency'), ENT_QUOTES, 'UTF-8'); ?></td>
						<td></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
		</form>

		<h3 class="jlcc-title-data"><?php echo Text::_('PLG_CONTENT_CONTENTCART_CLIENT_DATA'); ?></h3>
		<form name="cart_order" class="order" method="post" action="<?php echo htmlspecialchars(Uri::getInstance()->toString(), ENT_QUOTES, 'UTF-8'); ?>">
			<div class="jlcc-block-data">
				<input class="jlcc-input" type="hidden" name="mail" value="1"/>
				<?php if ($params->get('client_name') != '0') : ?>
					<div>
						<input class="jlcc-input" type="text" name="client_name" value="<?php echo $username; ?>" size="25"
							<?php if ($params->get('client_name') == '2') { echo ' required="required" aria-required="true" '; } ?>
							   placeholder="<?php echo Text::_('PLG_CONTENT_CONTENTCART_ENTER_NAME'); ?>"/>
					</div>
				<?php endif; ?>

				<?php if ($params->get('client_email') != '0') : ?>
					<div>
						<input class="jlcc-input" type="email" name="client_email" value="<?php echo $useremail; ?>" size="25"
							<?php if ($params->get('client_email') == '2') { echo ' required="required" aria-required="true" '; } ?>
							   placeholder="<?php echo Text::_('PLG_CONTENT_CONTENTCART_ENTER_EMAIL'); ?>" />
					</div>
				<?php endif; ?>

				<?php if ($params->get('client_phone') != '0') : ?>
					<div>
						<input class="jlcc-input" type="tel" name="client_phone" value="" size="25"
							<?php if ($params->get('client_phone') == '2') { echo ' required="required" aria-required="true" '; } ?>
							   placeholder="<?php echo Text::_('PLG_CONTENT_CONTENTCART_ENTER_PHONE'); ?>"/>
					</div>
				<?php endif; ?>

				<?php if ($params->get('client_note') != '0') : ?>
					<div>
						<textarea class="jlcc-textarea" name="client_note"
							<?php if ($params->get('client_note') == '2') { echo ' required="required" aria-required="true" '; } ?>
							placeholder="<?php
								$notePlaceholder = $params->get('title_note') ?: Text::_('PLG_CONTENT_CONTENTCART_CLIENT_NOTE');
								echo htmlspecialchars($notePlaceholder, ENT_QUOTES, 'UTF-8');
							?>"></textarea>
					</div>
				<?php endif; ?>

				<div>
					<input type="submit" class="validate jlcc-button jlcc-primary" value="<?php echo Text::_('PLG_CONTENT_CONTENTCART_TO_ORDER'); ?>"/>
				</div>
				<?php echo HTMLHelper::_('form.token'); ?>
			</div>
		</form>
	<?php endif; ?>
</div>
