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
	 * Cache for cart data from session (Performance optimization)
	 *
	 * @var    array|null
	 * @since  3.0.1
	 */
	private ?array $cartCache = null;

	/**
	 * Cache for category IDs filter (Performance optimization)
	 *
	 * @var    array|null
	 * @since  3.0.1
	 */
	private ?array $catidsCache = null;

	/**
	 * Cache for application areas (Performance optimization)
	 *
	 * @var    array|null
	 * @since  3.0.1
	 */
	private ?array $applicationAreasCache = null;

	/**
	 * Cache for cart URL (Performance optimization)
	 *
	 * @var    string|null
	 * @since  3.0.1
	 */
	private ?string $cartUrlCache = null;

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
			'onContentPrepare'       => 'onContentPrepare',
			'onAjaxContentcart'      => 'onAjaxContentcart',
		];
	}

	/**
	 * Register WebAsset file and load CSS on early routing event
	 *
	 * @return  void
	 *
	 * @since   2.0.0
	 */
	public function onAfterRoute(): void
	{
		if (!$this->getApplication()->isClient('site'))
		{
			return;
		}

		try
		{
			// Register asset file early
			$wa = Factory::getContainer()->get(WebAssetRegistry::class);

			// Try to register from file first
			$assetFile = JPATH_ROOT . '/media/plg_content_contentcart/joomla.asset.json';
			if (file_exists($assetFile))
			{
				$wa->addRegistryFile('media/plg_content_contentcart/joomla.asset.json');
			}
			else
			{
				// Fallback: register assets manually
				$wa->addRegistryFile('media/plg_content_contentcart/joomla.asset.json');

				// Also register manually as fallback
				$this->registerAssetsFallback($wa);

				if ($this->getApplication()->get('debug'))
				{
					$this->getApplication()->enqueueMessage('ContentCart: Asset file not found at ' . $assetFile . ', using fallback registration', 'warning');
				}
			}
		}
		catch (\Exception $e)
		{
			if ($this->getApplication()->get('debug'))
			{
				$this->getApplication()->enqueueMessage('ContentCart Asset Registration Error: ' . $e->getMessage(), 'warning');
			}
		}
	}

	/**
	 * Register assets manually (fallback if joomla.asset.json not found)
	 *
	 * @param   WebAssetRegistry  $wa  WebAsset Registry
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	private function registerAssetsFallback(WebAssetRegistry $wa): void
	{
		try
		{
			// Register CSS
			$wa->add('style', new \Joomla\CMS\WebAsset\WebAssetItem(
				'plg_content_contentcart.jlcontentcart',
				'plg_content_contentcart/css/jlcontentcart.css',
				['type' => 'style']
			));

			// Register main JS
			$wa->add('script', new \Joomla\CMS\WebAsset\WebAssetItem(
				'plg_content_contentcart.contentcart',
				'plg_content_contentcart/js/contentcart.js',
				['type' => 'script', 'attributes' => ['defer' => true]]
			));

			// Register init JS
			$wa->add('script', new \Joomla\CMS\WebAsset\WebAssetItem(
				'plg_content_contentcart.contentcart-init',
				'plg_content_contentcart/js/contentcart-init.js',
				['type' => 'script', 'dependencies' => ['plg_content_contentcart.contentcart'], 'attributes' => ['defer' => true]]
			));
		}
		catch (\Exception $e)
		{
			// Silent fail
		}
	}

	/**
	 * Flag to track if CSS was already loaded
	 *
	 * @var    boolean
	 * @since  3.0.1
	 */
	private bool $cssLoaded = false;

	/**
	 * Load CSS if enabled (called once per request)
	 *
	 * @return  void
	 *
	 * @since   3.0.1
	 */
	private function loadCss(): void
	{
		// Only load once per request
		if ($this->cssLoaded || !$this->params->get('enable_css', 1))
		{
			return;
		}

		$this->cssLoaded = true;

		try
		{
			$app = $this->getApplication();
			$document = $app->getDocument();

			// Ensure document is HtmlDocument
			if ($document instanceof \Joomla\CMS\Document\HtmlDocument)
			{
				$wam = $document->getWebAssetManager();
				$wam->useStyle('plg_content_contentcart.jlcontentcart');
			}
		}
		catch (\Exception $e)
		{
			if ($this->getApplication()->get('debug'))
			{
				$this->getApplication()->enqueueMessage('ContentCart CSS Load Error: ' . $e->getMessage(), 'warning');
			}
		}
	}

	/**
	 * Get cart data from session with caching (Performance optimization)
	 *
	 * @return  array  Cart data
	 *
	 * @since   3.0.1
	 */
	private function getCartData(): array
	{
		if ($this->cartCache === null)
		{
			$this->cartCache = $this->getApplication()->getSession()->get('content_order', []);

			if (!is_array($this->cartCache))
			{
				$this->cartCache = [];
			}
		}

		return $this->cartCache;
	}

	/**
	 * Get category IDs filter with caching (Performance optimization)
	 *
	 * @return  array  Category IDs
	 *
	 * @since   3.0.1
	 */
	private function getCategoryIds(): array
	{
		if ($this->catidsCache === null)
		{
			$this->catidsCache = $this->params->get('catid', []);
		}

		return $this->catidsCache;
	}

	/**
	 * Get application areas with caching (Performance optimization)
	 *
	 * @return  array  Application areas
	 *
	 * @since   3.0.1
	 */
	private function getApplicationAreas(): array
	{
		if ($this->applicationAreasCache === null)
		{
			$this->applicationAreasCache = $this->params->get('application_area', []);
		}

		return $this->applicationAreasCache;
	}

	/**
	 * Get cart URL with caching (Performance optimization)
	 *
	 * @return  string  Cart URL
	 *
	 * @since   3.0.1
	 */
	private function getCartUrl(): string
	{
		if ($this->cartUrlCache === null)
		{
			$menuItem = $this->params->get('mymenuitem');
			if ($menuItem)
			{
				$this->cartUrlCache = Route::_("index.php?Itemid=" . $menuItem);
			}
			else
			{
				$this->cartUrlCache = '';
			}
		}

		return $this->cartUrlCache;
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
			// PERF-003: Removed expensive debug_backtrace() call
			if ($debug)
			{
				$app->enqueueMessage('shouldDisplayButton: Button already added for article ' . $article->id . ' (context: ' . $context . ')', 'warning');
			}
			return false;
		}

		// PERF-006: Use cached category IDs instead of accessing params every time
		$catids = $this->getCategoryIds();
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

		// PERF-006: Use cached application areas instead of accessing params every time
		$applicationAreas = $this->getApplicationAreas();
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

		// Handle mail sending (order submission)
		if ($input->getInt('mail') && !$input->getInt('nosend'))
		{
			// CSRF Protection for order submission
			if (!Session::checkToken())
			{
				$app->enqueueMessage(Text::_('JINVALID_TOKEN'), 'error');
				// Redirect back to cart without processing order
				if ($cart_url)
				{
					$app->redirect($cart_url);
				}
				else
				{
					$app->redirect(Route::_('index.php'));
				}
				return;
			}

			// Try to get cart data from POST (localStorage), fallback to session
			$content_order = $this->getCartDataFromRequest($session);
			ContentcartHelper::sendOrderEmail($this->params, $content_order);
		}

		// Try to get cart data from POST (localStorage), fallback to session
		$content_order = $this->getCartDataFromRequest($session);

		// ALWAYS render cart layout (even if session is empty, localStorage may have items)
		// JavaScript will render items from localStorage if session is empty
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

	/**
	 * Get price from article's custom field (server-side validation)
	 *
	 * @param   object  $article  Article object
	 *
	 * @return  float  Price value or 0.0 if not found
	 *
	 * @since   3.0.1
	 */
	private function getPriceFromArticle(object $article): float
	{
		error_log('[ContentCart] getPriceFromArticle called');

		$app = $this->getApplication();

		// Check if pricing is enabled
		$using_price = $this->params->get('using_price');
		error_log('[ContentCart] getPriceFromArticle - using_price param: ' . var_export($using_price, true));

		if ($using_price != '1')
		{
			error_log('[ContentCart] getPriceFromArticle - pricing disabled');
			return 0.0;
		}

		$priceFieldId = $this->params->get('price_id');
		error_log('[ContentCart] getPriceFromArticle - price_id param: ' . var_export($priceFieldId, true));

		// Validate price field ID exists
		if (empty($priceFieldId))
		{
			error_log('[ContentCart] getPriceFromArticle - price_id not configured or empty');
			return 0.0;
		}

		error_log('[ContentCart] getPriceFromArticle - looking for field ID: ' . $priceFieldId);
		error_log('[ContentCart] getPriceFromArticle - available fields: ' . (isset($article->jcfields) ? implode(', ', array_keys($article->jcfields)) : 'none'));

		// Get price from article's custom fields
		if (isset($article->jcfields[$priceFieldId]))
		{
			error_log('[ContentCart] getPriceFromArticle - field exists, value: ' . var_export($article->jcfields[$priceFieldId]->value ?? 'NO VALUE', true));

			if (!empty($article->jcfields[$priceFieldId]->value))
			{
				$price = (float) $article->jcfields[$priceFieldId]->value;
				error_log('[ContentCart] getPriceFromArticle - found price: ' . $price);

				// Ensure price is not negative
				return max(0.0, $price);
			}
			else
			{
				error_log('[ContentCart] getPriceFromArticle - field value is empty');
			}
		}
		else
		{
			error_log('[ContentCart] getPriceFromArticle - field ID ' . $priceFieldId . ' not found in jcfields');
		}

		return 0.0;
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

		// CSRF Protection
		if (!Session::checkToken())
		{
			$app->enqueueMessage(Text::_('JINVALID_TOKEN'), 'error');
			$app->redirect(Route::_('index.php'));
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
			// Get price from server-side (secure - not from POST data)
			$price = $this->getPriceFromArticle($article);

			$content_order[] = [
				'article_id' => $article_id,
				'title'      => $input->getString('title'),
				'link'       => $input->getString('link'),
				'count'      => $input->getInt('count', 1),
				'price'      => $price,
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

	// PERF-002: Removed onContentAfterTitle and onContentBeforeDisplay
	// These were redundant wrappers causing unnecessary event processing

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
		// PERF-005: Load CSS once when content is being displayed
		$this->loadCss();

		// Load JavaScript assets
		$this->loadJavaScript();

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

		// PERF-006: Use cached cart URL instead of rebuilding every time
		$cart_url = $this->getCartUrl();

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
		// PERF-005: Load CSS once when content is being prepared
		$this->loadCss();

		// Load JavaScript assets
		$this->loadJavaScript();

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

		// PERF-006: Use cached cart URL instead of rebuilding every time
		$cart_url = $this->getCartUrl();

		$link = Route::_(\Joomla\Component\Content\Site\Helper\RouteHelper::getArticleRoute(
			$article->slug,
			$article->catid,
			$article->language
		));

		// CART PAGE HANDLING
		// Check if we should display cart
		$cartParam = $input->getInt('cart', 0);

		if ($app->get('debug'))
		{
			$app->enqueueMessage('onContentPrepare: Checking cart page - link=' . $link . ', cart_url=' . $cart_url . ', cart param=' . $cartParam, 'info');
		}

		if ($link == $cart_url || $cartParam == 1)
		{
			if ($app->get('debug'))
			{
				$app->enqueueMessage('onContentPrepare: Displaying cart page for article ' . $article->id, 'success');
			}
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

	/**
	 * Flag to track if JavaScript was already loaded
	 *
	 * @var    boolean
	 * @since  4.0.0
	 */
	private bool $jsLoaded = false;

	/**
	 * Get cart data from request (POST from localStorage) or session (fallback)
	 *
	 * @param   object  $session  Session object
	 *
	 * @return  array  Cart data
	 *
	 * @since   4.0.0
	 */
	private function getCartDataFromRequest(object $session): array
	{
		$app = $this->getApplication();
		$input = $app->getInput();

		// Try to get cart data from POST (from localStorage via JavaScript)
		$cartDataJson = $input->get('cart_data', '', 'raw');

		if (!empty($cartDataJson))
		{
			try
			{
				$cartData = json_decode($cartDataJson, true);

				if (is_array($cartData) && isset($cartData['items']) && is_array($cartData['items']))
				{
					// Преобразовать формат из localStorage в формат сессии
					$content_order = [];

					foreach ($cartData['items'] as $item)
					{
						if (!isset($item['id']) || !isset($item['title']))
						{
							continue;
						}

						$content_order[] = [
							'article_id' => (int) $item['id'],
							'title'      => (string) $item['title'],
							'link'       => (string) ($item['link'] ?? ''),
							'count'      => (int) ($item['count'] ?? 1),
							'price'      => (float) ($item['price'] ?? 0.0),
						];
					}

					return $content_order;
				}
			}
			catch (\Exception $e)
			{
				if ($app->get('debug'))
				{
					$app->enqueueMessage('ContentCart parse cart_data error: ' . $e->getMessage(), 'warning');
				}
			}
		}

		// Fallback: read from session (if JavaScript is disabled or old format)
		return $session->get('content_order', []);
	}

	/**
	 * Load JavaScript assets and configuration
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	private function loadJavaScript(): void
	{
		// Only load once per request
		if ($this->jsLoaded)
		{
			return;
		}

		$this->jsLoaded = true;

		try
		{
			$app = $this->getApplication();
			$document = $app->getDocument();

			// Ensure document is HtmlDocument
			if (!($document instanceof \Joomla\CMS\Document\HtmlDocument))
			{
				return;
			}

			$wam = $document->getWebAssetManager();

			// Add configuration options FIRST (before loading scripts)
			$options = [
				'apiUrl'       => 'index.php?option=com_ajax&plugin=contentcart&group=content&format=json',
				'token'        => Session::getFormToken() . '=1',
				'ttlDays'      => 30,
				'currency'     => $this->params->get('currency', ''),
			];

			$document->addScriptOptions('ContentCartOptions', $options);

			// Load JavaScript files (with defer attribute, will execute after DOM ready)
			$wam->useScript('plg_content_contentcart.contentcart');
			$wam->useScript('plg_content_contentcart.contentcart-init');
		}
		catch (\Exception $e)
		{
			if ($this->getApplication()->get('debug'))
			{
				$this->getApplication()->enqueueMessage('ContentCart JS Load Error: ' . $e->getMessage(), 'warning');
			}
		}
	}

	/**
	 * AJAX endpoint handler
	 *
	 * @param   Event  $event  The event object
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	public function onAjaxContentcart(Event $event): void
	{
		$app = $this->getApplication();
		$input = $app->input;

		// Проверка CSRF токена
		if (!Session::checkToken('get'))
		{
			throw new \Exception('Invalid token', 403);
		}

		// Получить method
		$method = $input->getCmd('method', '');

		if ($method === 'getPrice')
		{
			$articleId = $input->getInt('article_id', 0);

			if ($articleId <= 0)
			{
				throw new \Exception('Invalid article_id', 400);
			}

			// Загрузить статью
			$article = $this->loadArticle($articleId);

			if (!$article)
			{
				throw new \Exception('Article not found', 404);
			}

			// Получить цену
			$price = $this->getPriceFromArticle($article);

			// Вернуть данные через event (Joomla 5 способ)
			$result = [
				'price' => $price,
			];

			// Joomla 5: используем ResultAwareInterface или setArgument
			if ($event instanceof \Joomla\Event\ResultAwareInterface)
			{
				$event->addResult($result);
			}
			else
			{
				// Fallback для generic events
				$eventResult = $event->getArgument('result') ?? [];
				$eventResult[] = $result;
				$event->setArgument('result', $eventResult);
			}
		}
		else
		{
			throw new \Exception('Unknown method', 400);
		}
	}

	/**
	 * AJAX метод для получения цены товара
	 *
	 * @return  void
	 *
	 * @since   4.0.0
	 */
	private function getPriceAjax()
	{
		error_log('[ContentCart] getPriceAjax called');

		$app = $this->getApplication();
		$input = $app->input;

		$articleId = $input->getInt('article_id', 0);
		error_log('[ContentCart] getPriceAjax - article_id from input: ' . $articleId);

		if ($articleId <= 0)
		{
			error_log('[ContentCart] getPriceAjax - invalid article_id');
			throw new \Exception('Invalid article_id', 400);
		}

		// Загрузить статью
		error_log('[ContentCart] getPriceAjax - loading article...');
		$article = $this->loadArticle($articleId);

		if (!$article)
		{
			error_log('[ContentCart] getPriceAjax - article not found');
			throw new \Exception('Article not found', 404);
		}

		error_log('[ContentCart] getPriceAjax - article loaded successfully');
		error_log('[ContentCart] getPriceAjax - has jcfields: ' . (isset($article->jcfields) ? 'yes' : 'no'));
		if (isset($article->jcfields))
		{
			error_log('[ContentCart] getPriceAjax - jcfields count: ' . count($article->jcfields));
			error_log('[ContentCart] getPriceAjax - jcfields keys: ' . implode(', ', array_keys($article->jcfields)));
		}

		// Получить цену
		error_log('[ContentCart] getPriceAjax - getting price from article...');
		$price = $this->getPriceFromArticle($article);
		error_log('[ContentCart] getPriceAjax - price returned: ' . $price);

		// Вернуть данные - Joomla автоматически сериализует в JSON
		$result = (object) [
			'price' => $price,
			'debug' => (object) [
				'article_id' => $articleId,
				'has_jcfields' => isset($article->jcfields),
				'jcfields_count' => isset($article->jcfields) ? count($article->jcfields) : 0,
				'jcfields_keys' => isset($article->jcfields) ? array_keys($article->jcfields) : [],
				'using_price' => $this->params->get('using_price'),
				'price_field_id' => $this->params->get('price_id'),
			]
		];
		error_log('[ContentCart] getPriceAjax - returning: ' . json_encode($result));
		return $result;
	}

	/**
	 * Загрузка статьи по ID
	 *
	 * @param   int  $articleId  ID статьи
	 *
	 * @return  object|null  Объект статьи или null
	 *
	 * @since   4.0.0
	 */
	private function loadArticle(int $articleId): ?object
	{
		try
		{
			$db = Factory::getDbo();
			$query = $db->getQuery(true)
				->select('a.*')
				->from($db->quoteName('#__content', 'a'))
				->where($db->quoteName('a.id') . ' = :id')
				->bind(':id', $articleId, \Joomla\Database\ParameterType::INTEGER);

			$db->setQuery($query);
			$article = $db->loadObject();

			if (!$article)
			{
				return null;
			}

			// Загрузить custom fields и присвоить к объекту статьи
			$fields = \Joomla\Component\Fields\Administrator\Helper\FieldsHelper::getFields('com_content.article', $article);

			// Преобразовать массив в ассоциативный массив по ID поля
			$article->jcfields = [];
			foreach ($fields as $field)
			{
				$article->jcfields[$field->id] = $field;
			}

			return $article;
		}
		catch (\Exception $e)
		{
			if ($this->getApplication()->get('debug'))
			{
				$this->getApplication()->enqueueMessage('ContentCart loadArticle Error: ' . $e->getMessage(), 'warning');
			}
			return null;
		}
	}
}
