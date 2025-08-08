<?php

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri; // Добавлено для Uri::getInstance() если потребуется

// No direct access
defined('_JEXEC') or die;

/**
 * Content Cart
 *
 * @version 	@version@
 * @author		Joomline
 * @copyright	(C) 2018 Efanych (efanych@gmail.com), Joomline. All rights reserved.
 * @license 	GNU General Public License version 2 or later; see	LICENSE.txt
 */
class plgContentcontentcart extends CMSPlugin
{
	/**
	 * Class Constructor
	 *
	 * @param   object  $subject
	 * @param   array   $config
	 */
	public function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		$this->loadLanguage();
	}

	public function onContentAfterDisplay($context, &$row, &$params, $page = 0)
	{
		$app = Factory::getApplication();
		$input = $app->input;
		$session = $app->getSession();
		if ($input->getInt('delete') !== null)
		{
			$content_order = $session->get('content_order');
			unset($content_order[$input->getInt('delete')]);
			sort($content_order);
			$session->set('content_order', $content_order);
			$app->redirect(Uri::getInstance()->toString());
		}

		if (
			($this->params->get('category_filtering_type') == '0' && in_array($row->catid, $this->params->get('catid')))
			or ($this->params->get('category_filtering_type') == '1' && !in_array($row->catid, $this->params->get('catid')))
		) {
			return;
		}
		if (!in_array($context, $this->params->get('application_area')))
		{
			return;
		}
		$link = Route::_(\Joomla\Component\Content\Site\Helper\RouteHelper::getArticleRoute($row->slug, $row->catid, $row->language));
		if ($input->getInt('add'))
		{
			$msg = '';
			$content_order = $session->get('content_order', []);

			$article_id = $input->getInt('article_id');
			$is_in_cart = array_search($article_id, array_column($content_order, 'article_id'));

			if ($article_id == $row->id && $is_in_cart === false)
			{
				$content_order[] = [
					'article_id' => $article_id,
					'title'      => $input->getString('title'),
					'link'       => $input->getString('link'),
					'count'      => $input->getInt('count'),
					'price'      => $input->get('price'),
				];
				$msg             = Text::_('CONTENTCART_ADDED');
				$session->set('content_order', $content_order);
			}
			$app->enqueueMessage($msg, 'message');
		}
		ob_start();
		include PluginHelper::getLayoutPath('content', 'contentcart', 'default');
		$html = ob_get_clean();

		return $html;
	}

	public function onContentPrepare($context, $article, $params, $page = 0)
	{
		if ($context != 'com_content.article')
		{
			return;
		}
		$app      = Factory::getApplication();
		$input = $app->input;
		$session  = $app->getSession();
		$cart_url = Route::_("index.php?Itemid=" . $this->params->get('mymenuitem'));
		$link     = Route::_(\Joomla\Component\Content\Site\Helper\RouteHelper::getArticleRoute($article->slug, $article->catid, $article->language));

		if($input->getInt('mail') && !$input->getInt('nosend'))
		{
			require_once __DIR__ . '/helper/contentcart.php';
			PlgContentContentcartHelper::sendOrderEmail($this->params, $session->get('content_order'));
		}

		if ($input->getInt('cart', 0) == 0 && $link != $cart_url)
		{
			return;
		}

		if ($session->get('content_order'))
		{
			$template = $app->getTemplate();
			$client   = ucfirst($app->getName());

			$view = $app->bootComponent('com_content')->getMVCFactory()->getView('article',  Factory::getDocument()->getType(), $client);

			$basePath = JPATH_ROOT . '/plugins/content/contentcart/tmpl/';
			if (is_file(JPATH_ROOT . '/templates/' . $template . '/html/plg_content_contentcart/cart.php'))
			{
				$basePath = JPATH_ROOT . '/templates/' . $template . '/html/plg_content_contentcart/';
			}
			

			$view->addTemplatePath($basePath);
			$view->setLayout('cart');
			if (!$this->params->get('mymenuitem'))
			{
				$doc = Factory::getDocument();
				$doc->setTitle(Text::_('CONTENTCART_SHOPPING_CART'));
			}
		}
		elseif ($link != $cart_url)
		{
			$app->redirect($link);
		}
	}

}
