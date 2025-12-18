<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.contentcart
 *
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Joomline\Plugin\Content\Contentcart\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Event\Content\ContentPrepareEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\WebAsset\WebAssetRegistry;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Joomline\Plugin\Content\Contentcart\Helper\ContentcartHelper;

/**
 * Content Cart Plugin
 *
 * @since  2.0.0
 */
final class Contentcart extends CMSPlugin implements SubscriberInterface
{
	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 * @since  2.0.0
	 */
	protected $autoloadLanguage = true;

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 *
	 * @since   2.0.0
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onAfterRoute'           => 'onAfterRoute',
			'onContentAfterDisplay'  => 'onContentAfterDisplay',
			'onContentBeforeDisplay' => 'onContentBeforeDisplay',
			'onContentAfterTitle'    => 'onContentAfterTitle',
			'onContentPrepare'       => 'onContentPrepare',
		];
	}

	/**
	 * Register WebAsset file on early routing event
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	public function onAfterRoute(): void
	{
		if ($this->getApplication()->isClient('site'))
		{
			try
			{
				$wa = Factory::getContainer()->get(WebAssetRegistry::class);
				$wa->addRegistryFile('media/plg_content_contentcart/joomla.asset.json');
			}
			catch (\Exception $e)
			{
				$this->getApplication()->enqueueMessage('ContentCart Asset Registration Error: ' . $e->getMessage(), 'error');
			}
		}
	}

	/**
	 * Check if button should be displayed for this article
	 *
	 * @param   object   $article        Article object
	 * @param   string   $context        Content context
	 * @param   boolean  $skipFlagCheck  Skip checking contentcart_button_added flag (for blog contexts where events fire multiple times)
	 *
	 * @return  boolean
	 *
	 * @since   2.0.0
	 */
	private function shouldDisplayButton(object $article, string $context, bool $skipFlagCheck = false): bool
	{
		$app = $this->getApplication();
		$debug = $app->get('debug');

		// Проверка наличия обязательных свойств
		if (!isset($article->catid) || !isset($article->id) || !isset($article->slug))
		{
			if ($debug)
			{
				$app->enqueueMessage('shouldDisplayButton: Missing required properties for article ' . ($article->id ?? 'unknown'), 'warning');
			}
			return false;
		}

		// Проверка, что кнопка еще не была добавлена (skip if requested)
		if (!$skipFlagCheck && isset($article->contentcart_button_added) && $article->contentcart_button_added === true)
		{
			if ($debug)
			{
				// Debug: show stack trace to find WHO set this flag
				$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
				$caller = '';
				foreach ($trace as $t) {
					if (isset($t['file']) && isset($t['line'])) {
						$caller .= basename($t['file']) . ':' . $t['line'] . ' -> ';
					}
				}
				$app->enqueueMessage('shouldDisplayButton: Button already added for article ' . $article->id . ' (context: ' . $context . ', called from: ' . $caller . ')', 'warning');
			}
			return false;
		}

		// Проверка фильтра по категориям
		$catids = $this->params->get('catid', []);
		if (!empty($catids) && is_array($catids))
		{
			if (
				($this->params->get('category_filtering_type') == '0' && in_array($article->catid, $catids))
				|| ($this->params->get('category_filtering_type') == '1' && !in_array($article->catid, $catids))
			)
			{
				if ($debug)
				{
					$app->enqueueMessage('shouldDisplayButton: Category filter blocked article ' . $article->id, 'warning');
				}
				return false;
			}
		}

		// Проверка application_area
		$applicationAreas = $this->params->get('application_area', []);
		if ($debug)
		{
			$app->enqueueMessage('shouldDisplayButton: application_area=' . json_encode($applicationAreas) . ', context=' . $context . ', in_array=' . (in_array($context, $applicationAreas) ? 'true' : 'false'), 'info');
		}

		if (!empty($applicationAreas) && is_array($applicationAreas) && !in_array($context, $applicationAreas))
		{
			if ($debug)
			{
				$app->enqueueMessage('shouldDisplayButton: Application area check failed for article ' . $article->id, 'warning');
			}
			return false;
		}

		return true;
	}

	/**
	 * Render add to cart button HTML
	 *
	 * @param   object  $article   Article object
	 * @param   string  $link      Article link
	 * @param   string  $cart_url  Cart URL
	 *
	 * @return  string  Button HTML or empty string
	 *
	 * @since   2.0.0
	 */
	private function renderButton(object $article, string $link, string $cart_url): string
	{
		$session = $this->getApplication()->getSession();

		// Prepare display data for layout
		$displayData = [
			'row'       => $article,
			'params'    => $this->params,
			'link'      => $link,
			'cart_url'  => $cart_url,
			'session'   => $session,
		];

		// Render layout from plugin's layouts folder
		try
		{
			return LayoutHelper::render('default', $displayData, JPATH_PLUGINS . '/content/contentcart/layouts');
		}
		catch (\Exception $e)
		{
			// Log error for debugging
			$this->getApplication()->enqueueMessage('ContentCart Layout Error: ' . $e->getMessage(), 'error');
			return '';
		}
	}

	/**
	 * Handle cart display on cart page
	 *
	 * @param   object  $article   Article object
	 * @param   string  $link      Current article link
	 * @param   string  $cart_url  Cart URL
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	private function handleCartDisplay(object $article, string $link, string $cart_url): void
	{
		$app     = $this->getApplication();
		$input   = $app->getInput();
		$session = $app->getSession();

		// Handle mail sending
		if ($input->getInt('mail') && !$input->getInt('nosend'))
		{
			$content_order = $session->get('content_order', []);
			ContentcartHelper::sendOrderEmail($this->params, $content_order);
		}

		$content_order = $session->get('content_order', []);

		if (!empty($content_order))
		{
			// Prepare display data for cart layout
			$displayData = [
				'content_order' => $content_order,
				'params'        => $this->params,
				'session'       => $session,
			];

			// Render cart layout from plugin's layouts folder
			$article->text = LayoutHelper::render('cart', $displayData, JPATH_PLUGINS . '/content/contentcart/layouts');

			if (!$this->params->get('mymenuitem'))
			{
				$doc = $app->getDocument();
				$doc->setTitle(Text::_('PLG_CONTENT_CONTENTCART_SHOPPING_CART'));
			}
		}
		elseif ($link != $cart_url)
		{
			$app->redirect($link);
		}
	}

	/**
	 * Handle add to cart action
	 *
	 * @param   object  $article  Article object
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	private function handleAddToCart(object $article): void
	{
		$app     = $this->getApplication();
		$input   = $app->getInput();
		$session = $app->getSession();

		// Handle add to cart action
		if (!$input->getInt('add'))
		{
			return;
		}

		$msg           = '';
		$content_order = $session->get('content_order', []);

		if (!is_array($content_order))
		{
			$content_order = [];
		}

		$article_id = $input->getInt('article_id');
		$is_in_cart = false;

		if (!empty($content_order))
		{
			try
			{
				$is_in_cart = array_search($article_id, array_column($content_order, 'article_id'));
			}
			catch (\Throwable $e)
			{
				// In case of malformed data in session, reset cart
				$session->set('content_order', []);
				$content_order = [];
				$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_ERROR_DATA_CORRUPTED'), 'warning');
				$is_in_cart = false;
			}
		}

		if ($article_id == $article->id && $is_in_cart === false)
		{
			$content_order[] = [
				'article_id' => $article_id,
				'title'      => $input->getString('title'),
				'link'       => $input->getString('link'),
				'count'      => $input->getInt('count', 1),
				'price'      => $input->getFloat('price', 0.0),
			];
			$msg = Text::_('PLG_CONTENT_CONTENTCART_ADDED');
			$session->set('content_order', $content_order);
		}

		if ($msg)
		{
			$app->enqueueMessage($msg, 'message');
		}
	}

	/**
	 * Handle delete action from cart
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	private function handleDelete(): void
	{
		$app   = $this->getApplication();
		$input = $app->getInput();

		// Handle delete action with CSRF protection
		if ($input->get('delete') === null)
		{
			return;
		}

		// CSRF protection
		if (!Session::checkToken('get'))
		{
			$app->enqueueMessage(Text::_('JINVALID_TOKEN'), 'error');
			// Build redirect URL from current request without delete params
			$menuItem = $this->params->get('mymenuitem');
			if ($menuItem)
			{
				$app->redirect(Route::_("index.php?Itemid=" . $menuItem, false));
			}
			else
			{
				// Fallback to home
				$app->redirect(Uri::base());
			}
			return;
		}

		$session       = $app->getSession();
		$content_order = $session->get('content_order', []);
		$deleteKey     = $input->getInt('delete');

		if (is_array($content_order) && isset($content_order[$deleteKey]))
		{
			unset($content_order[$deleteKey]);
			$session->set('content_order', array_values($content_order));
			$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_ITEM_DELETED'), 'success');
		}

		// Redirect back to cart page (clean URL)
		$menuItem = $this->params->get('mymenuitem');
		if ($menuItem)
		{
			$app->redirect(Route::_("index.php?Itemid=" . $menuItem, false));
		}
		else
		{
			// Fallback: try to build clean URL from current URI
			$uri = Uri::getInstance();
			$uri->setVar('delete', null);
			$uri->setVar(Session::getFormToken(), null);
			$cleanUrl = $uri->toString(['scheme', 'host', 'port', 'path']);
			$app->redirect($cleanUrl);
		}
	}

	/**
	 * Content after title event - adds button directly to content
	 *
	 * @param   Event  $event  The event object
	 *
	 * @return  string
	 *
	 * @since   2.0.0
	 */
	public function onContentAfterTitle(Event $event): string
	{
		// Use the same logic as afterDisplay
		return $this->onContentAfterDisplay($event);
	}

	/**
	 * Content before display event (alternative to afterDisplay for better compatibility)
	 *
	 * @param   Event  $event  The event object
	 *
	 * @return  string
	 *
	 * @since   2.0.0
	 */
	public function onContentBeforeDisplay(Event $event): string
	{
		// Use the same logic as afterDisplay
		return $this->onContentAfterDisplay($event);
	}

	/**
	 * Content after display event (fallback for custom templates)
	 *
	 * @param   Event  $event  The event object
	 *
	 * @return  string
	 *
	 * @since   2.0.0
	 */
	public function onContentAfterDisplay(Event $event): string
	{
		// Extract parameters from event
		$context = $event->getArgument('context');
		$row     = $event->getArgument('item');

		// Skip com_content.categories context (category objects, not articles)
		if ($context === 'com_content.categories')
		{
			return '';
		}

		$app = $this->getApplication();

		// For article view, check if button was already added by onContentPrepare
		if ($context === 'com_content.article' && isset($row->contentcart_button_added) && $row->contentcart_button_added === true)
		{
			if ($app->get('debug'))
			{
				$app->enqueueMessage('onContentAfterDisplay: Button already added in onContentPrepare for article ' . $row->id, 'info');
			}
			return '';
		}

		// For blog/category contexts, check flag but with special handling
		// These events fire MULTIPLE times per article, we only want to render ONCE
		if (($context === 'com_content.category' || $context === 'com_content.featured'))
		{
			if (isset($row->contentcart_button_added) && $row->contentcart_button_added === true)
			{
				if ($app->get('debug'))
				{
					$app->enqueueMessage('onContentAfterDisplay: Button already rendered for article ' . $row->id . ' (blog context), returning empty', 'info');
				}
				return '';
			}

			// Check if we should display button (WITHOUT checking the flag - we already checked above)
			if (!$this->shouldDisplayButton($row, $context, true))
			{
				if ($app->get('debug'))
				{
					$app->enqueueMessage('onContentAfterDisplay: shouldDisplayButton returned false for article ' . $row->id, 'info');
				}
				return '';
			}
		}
		else
		{
			// For other contexts, use normal check
			if (!$this->shouldDisplayButton($row, $context))
			{
				return '';
			}
		}

		// Get URLs
		$cart_url = '';
		$menuItem = $this->params->get('mymenuitem');
		if ($menuItem)
		{
			$cart_url = Route::_("index.php?Itemid=" . $menuItem);
		}

		$link = Route::_(\Joomla\Component\Content\Site\Helper\RouteHelper::getArticleRoute(
			$row->slug,
			$row->catid,
			$row->language
		));

		// Render and return button as fallback
		$html = $this->renderButton($row, $link, $cart_url);

		// Mark button as added
		$row->contentcart_button_added = true;

		if ($app->get('debug'))
		{
			$app->enqueueMessage('onContentAfterDisplay: Returning HTML (' . strlen($html) . ' bytes) for article ' . $row->id . ' (context: ' . $context . ')', 'success');
		}

		return $html;
	}

	/**
	 * Content prepare event
	 *
	 * @param   ContentPrepareEvent  $event  The event object
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	public function onContentPrepare(ContentPrepareEvent $event): void
	{
		// Extract parameters from event
		$context = $event->getContext();
		$article = $event->getItem();
		$params  = $event->getParams();
		$page    = $event->getArgument('page', 0);

		// Debug: log context
		$app = $this->getApplication();
		if ($app->get('debug'))
		{
			$app->enqueueMessage('ContentCart onContentPrepare: context=' . $context . ', article_id=' . ($article->id ?? 'null'), 'info');
		}

		// Only process com_content contexts
		if (!str_starts_with($context, 'com_content.'))
		{
			return;
		}

		// Skip com_content.categories context (these are category objects, not articles)
		if ($context === 'com_content.categories')
		{
			return;
		}

		// Only process article objects with required properties
		if (!isset($article->slug) || !isset($article->catid) || !isset($article->language))
		{
			return;
		}

		$app   = $this->getApplication();
		$input = $app->getInput();

		// Handle delete action first (before any content manipulation)
		$this->handleDelete();

		// Get URLs
		$cart_url = '';
		$menuItem = $this->params->get('mymenuitem');
		if ($menuItem)
		{
			$cart_url = Route::_("index.php?Itemid=" . $menuItem);
		}

		$link = Route::_(\Joomla\Component\Content\Site\Helper\RouteHelper::getArticleRoute(
			$article->slug,
			$article->catid,
			$article->language
		));

		// CART PAGE HANDLING
		// Check if we should display cart
		if ($link == $cart_url || $input->getInt('cart', 0) == 1)
		{
			$this->handleCartDisplay($article, $link, $cart_url);
			return;
		}

		// BUTTON INSERTION LOGIC
		// Debug: check article properties for categories context
		if ($app->get('debug') && $context === 'com_content.categories')
		{
			$app->enqueueMessage('onContentPrepare: Article 10 properties - class=' . get_class($article) . ', has_introtext=' . (isset($article->introtext) ? 'yes' : 'no') . ', has_text=' . (isset($article->text) ? 'yes' : 'no'), 'info');
		}

		// Check if button should be displayed
		$shouldDisplay = $this->shouldDisplayButton($article, $context);

		if ($app->get('debug'))
		{
			$app->enqueueMessage('onContentPrepare: shouldDisplayButton result=' . ($shouldDisplay ? 'true' : 'false') . ' for article ' . $article->id, 'info');
		}

		if (!$shouldDisplay)
		{
			return;
		}

		if ($app->get('debug'))
		{
			$app->enqueueMessage('onContentPrepare: Proceeding with button insertion for article ' . $article->id, 'info');
		}

		// Handle add to cart action
		$this->handleAddToCart($article);

		// Render button HTML
		$buttonHtml = $this->renderButton($article, $link, $cart_url);

		if (empty($buttonHtml))
		{
			if ($app->get('debug'))
			{
				$app->enqueueMessage('onContentPrepare: renderButton returned empty HTML for article ' . $article->id, 'warning');
			}
			return;
		}

		if ($app->get('debug'))
		{
			$app->enqueueMessage('onContentPrepare: Button HTML rendered (' . strlen($buttonHtml) . ' bytes) for article ' . $article->id, 'info');
		}

		// Insert button based on context
		switch ($context)
		{
			case 'com_content.category':
			case 'com_content.featured':
				// Blog/Featured views - prepend to BOTH introtext and text (Joomla 5 doesn't output display event results in blog)
				if (!isset($article->introtext))
				{
					$article->introtext = '';
				}
				$article->introtext = $buttonHtml . $article->introtext;

				// Also add to text in case blog layout uses it
				if (!isset($article->text))
				{
					$article->text = '';
				}
				$article->text = $buttonHtml . $article->text;

				if ($app->get('debug'))
				{
					$app->enqueueMessage('onContentPrepare: Button prepended to introtext for article ' . $article->id . ' (introtext length: ' . strlen($article->introtext) . ' bytes, text length: ' . strlen($article->text) . ' bytes)', 'success');
				}
				break;

			case 'com_content.article':
				// Article view - prepend to text
				if (!isset($article->text))
				{
					$article->text = '';
				}
				$article->text = $buttonHtml . $article->text;

				if ($app->get('debug'))
				{
					$app->enqueueMessage('onContentPrepare: Button prepended to text for article ' . $article->id . ' (text length: ' . strlen($article->text) . ' bytes)', 'success');
				}
				break;
		}

		// Mark button as added to prevent duplication
		$article->contentcart_button_added = true;
	}
}
