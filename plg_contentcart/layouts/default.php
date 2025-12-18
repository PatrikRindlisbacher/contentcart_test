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
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * Layout variables
 * @var array    $displayData Display data array
 * @var object   $row         Article object
 * @var Registry $params      Plugin parameters
 * @var string   $link        Article link
 * @var string   $cart_url    Cart URL
 * @var object   $session     Session object
 */

// Extract variables from displayData
$row       = $displayData['row'] ?? null;
$params    = $displayData['params'] ?? null;
$link      = $displayData['link'] ?? '';
$cart_url  = $displayData['cart_url'] ?? '';
$session   = $displayData['session'] ?? null;

// Safety check
if (!$row || !$params || !$session) {
	return;
}

// PERF-005: CSS is now loaded centrally in onAfterRoute, no need to load here

$content_order = $session->get('content_order', []);
$in_cart = false;

if (!empty($content_order))
{
	try
	{
		$in_cart = array_search($row->id, array_column($content_order, 'article_id')) !== false;
	}
	catch (\Throwable $e)
	{
		// In case of malformed data in session
		$in_cart = false;
	}
}

if (!$in_cart && $link !== $cart_url)
{
	?>
	<!-- ContentCart: Add to cart button START -->
	<div class="jlcontentcart">
		<form action="<?php echo htmlspecialchars(Uri::getInstance()->toString(), ENT_QUOTES, 'UTF-8'); ?>" method="post">
			<input type="hidden" name="add" value="1"/>
			<input type="hidden" name="article_id" value="<?php echo (int) $row->id; ?>"/>
			<input type="hidden" name="title" value="<?php echo htmlspecialchars($row->title, ENT_QUOTES, 'UTF-8'); ?>"/>
			<input type="hidden" name="link" value="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>"/>
			<input type="submit" class="jlcc-button jlcc-primary" value="<?php echo Text::_('PLG_CONTENT_CONTENTCART_ADD_TO_CART'); ?>"/>
			<input type="number" name="count" max="999" min="1" value="1" class="jlcc-input jlcc-count">
			<?php echo HTMLHelper::_('form.token'); ?>
		</form>
	</div>
	<!-- ContentCart: Add to cart button END -->
	<?php
}
elseif ($link !== $cart_url && $cart_url)
{
	?>
	<!-- ContentCart: Go to cart button START -->
	<div class="to-cart">
		<a class="jlcc-button jlcc-success" href="<?php echo htmlspecialchars($cart_url, ENT_QUOTES, 'UTF-8'); ?>">
			<?php echo Text::_('PLG_CONTENT_CONTENTCART_GO_TO_CART'); ?>
		</a>
	</div>
	<!-- ContentCart: Go to cart button END -->
	<?php
}
