<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_contentcart
 *
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/**
 * Layout variables
 * @var Joomla\Registry\Registry             $params
 * @var string                               $moduleclass_sfx
 * @var array                                $cartData
 */

use Joomla\CMS\Factory;

// Get module class suffix
$moduleclass_sfx = $moduleclass_sfx ?? '';

// Get cart data from module variables
$content_order = $cartData['content_order'] ?? [];
$total         = $cartData['total'] ?? 0;
$item_count    = $cartData['item_count'] ?? 0;
$cart_url      = $cartData['cart_url'] ?? '';
$currency      = htmlspecialchars($cartData['currency'] ?? '', ENT_QUOTES, 'UTF-8');
$using_price   = $cartData['using_price'] ?? 0;

// PERF-005: CSS is loaded centrally by the plugin in onAfterRoute, no need to load here
?>

<div class="content_cart jlcontentcart<?php echo $moduleclass_sfx; ?>">
    <p class="count">
        <span><?php echo Text::_('MOD_CONTENTCART_PRODUCTS_COUNT'); ?>: </span>
        <span><?php echo $item_count; ?></span>
    </p>
	<?php if ($using_price == '1') : ?>
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
