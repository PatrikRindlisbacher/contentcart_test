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
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

$doc = Factory::getDocument();

if ($this->params->get('mymenuitem'))
{
	$cart_url = Route::_("index.php?Itemid=" . $this->params->get('mymenuitem'));
}
else
{
	$content_order = $session->get('content_order', []);
	$cart_url = '';
	if (!empty($content_order[0]['link'])){
		try {
			$uri = Uri::getInstance($content_order[0]['link']);
			$uri->setVar('cart', '1');
			$cart_url = $uri->toString();
		} catch (\Exception $e) {
			// Handle error if link is invalid
	           $cart_url = Route::_('index.php?option=com_content&view=article&id='.(int)($content_order[0]['article_id'] ?? 0).'&cart=1');
		}
	}
}

if ($params->get('enable_css', 1))
{
	$wa = Factory::getApplication()->getDocument()->getWebAssetManager();
	$wa->registerAndUseStyle('plg_content_contentcart.front', 'plugins/content/contentcart/assets/css/jlcontentcart.css');
}

$content_order = $session->get('content_order', []);
$in_cart = false;
if (!empty($content_order)) {
    try {
        $in_cart = array_search($row->id, array_column($content_order, 'article_id')) !== false;
    } catch (\Throwable $e) {
        // In case of malformed data in session
        $in_cart = false;
    }
}

if (!$in_cart && $link !== $cart_url) {
	?>
    <div class="jlcontentcart">
        <form action="<?php echo htmlspecialchars(Uri::getInstance()->toString(), ENT_QUOTES, 'UTF-8'); ?>" method="post">
            <input type="hidden" name="add" value="1"/>
            <input type="hidden" name="article_id" value="<?php echo (int) $row->id; ?>"/>
            <input type="hidden" name="title" value="<?php echo htmlspecialchars($row->title, ENT_QUOTES, 'UTF-8'); ?>"/>
            <input type="hidden" name="link" value="<?php echo htmlspecialchars($link, ENT_QUOTES, 'UTF-8'); ?>"/>
			<?php
			if ($this->params->get('using_price') == '1') {
				$price_id = $this->params->get('price_id');
				$price = 0;
				if (!empty($price_id) && isset($row->jcfields[$price_id]) && !empty($row->jcfields[$price_id]->value)) {
					$price = $row->jcfields[$price_id]->value;
				}
				?>
                <input type="hidden" name="price" value="<?php echo htmlspecialchars($price, ENT_QUOTES, 'UTF-8'); ?>"/>
				<?php
			} ?>
            <input type="submit" class="jlcc-button jlcc-primary" value="<?php echo Text::_('PLG_CONTENT_CONTENTCART_ADD_TO_CART'); ?>"/>
            <input type="number" name="count" max="999" min="1" value="1" class="jlcc-input jlcc-count">
			<?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </div>
<?php
} elseif ($link !== $cart_url && $cart_url) {
    ?>
    <div class="to-cart">
        <a class="jlcc-button jlcc-success" href="<?php echo htmlspecialchars($cart_url, ENT_QUOTES, 'UTF-8'); ?>">
			<?php echo Text::_('PLG_CONTENT_CONTENTCART_GO_TO_CART'); ?>
        </a>
    </div>
<?php
}
?>

