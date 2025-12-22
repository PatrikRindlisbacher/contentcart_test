<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.contentcart
 *
 * @copyright   (C) 2018-2025 Joomline. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Joomline\Plugin\Content\Contentcart\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Workflow\Workflow;
use Joomla\Registry\Registry;

/**
 * Content Cart Helper
 *
 * @since  2.0.0
 */
final class ContentcartHelper
{
	/**
	 * Send order email
	 *
	 * @param   Registry  $params         Plugin parameters
	 * @param   array     $content_order  Cart content
	 *
	 * @return  boolean  True on success, false on failure
	 *
	 * @since   2.0.0
	 */
	public static function sendOrderEmail(Registry $params, array $content_order): bool
	{
		if (empty($content_order) || !is_array($content_order))
		{
			return false;
		}

		$app    = Factory::getApplication();
		$input  = $app->getInput();
		$user   = $app->getIdentity();
		$filter = InputFilter::getInstance();

		// Get and validate client data
		$clientName  = $filter->clean($input->getString('client_name'), 'STRING');
		$clientEmail = $filter->clean($input->getString('client_email'), 'STRING');
		$clientPhone = $filter->clean($input->getString('client_phone'), 'STRING');
		$clientNote  = $filter->clean($input->getString('client_note', ''), 'STRING');

		// Validate email address if provided
		if (!empty($clientEmail) && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL))
		{
			$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_INVALID_EMAIL'), 'error');
			return false;
		}

		// Get mailer using new MailerFactory API
		try
		{
			/** @var MailerFactoryInterface $mailerFactory */
			$mailerFactory = Factory::getContainer()->get(MailerFactoryInterface::class);
			$mailer        = $mailerFactory->createMailer();
		}
		catch (\Exception $e)
		{
			$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_MAIL_SEND_ERROR') . ': ' . $e->getMessage(), 'error');
			return false;
		}

		// Prepare layout data for email
		$layoutData = [
			'params'        => $params,
			'content_order' => $content_order,
			'client_name'   => $clientName,
			'client_email'  => $clientEmail,
			'client_phone'  => $clientPhone,
			'client_note'   => $clientNote,
			'title_note'    => $params->get('title_note') ? $params->get('title_note') : Text::_('PLG_CONTENT_CONTENTCART_CLIENT_NOTE'),
		];

		$layoutPath = JPATH_PLUGINS . '/content/contentcart/layouts';
		$body = LayoutHelper::render('mail', $layoutData, $layoutPath);

		if (empty($body))
		{
			$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_MAIL_BODY_EMPTY_ERROR'), 'error');
			return false;
		}

		$senderEmail = $app->get('mailfrom');
		$senderName  = $app->get('fromname');
		$adminEmail  = $app->get('mailfrom');

		// Send email to admin
		$mailer->isHtml(true);
		$mailer->setSubject(Text::_('PLG_CONTENT_CONTENTCART_ORDER_INFO'));
		$mailer->setBody($body);
		$mailer->addRecipient($adminEmail);

		try
		{
			$mailer->setSender([$senderEmail, $senderName]);
		}
		catch (\Exception $e)
		{
			$app->enqueueMessage('Failed to set sender: ' . $e->getMessage(), 'error');
			return false;
		}

		try
		{
			$result = $mailer->send();

			if ($app->get('debug'))
			{
				$app->enqueueMessage('Email sent to admin: ' . $adminEmail, 'success');
			}

			if ($result === false || $result instanceof \Exception)
			{
				$errorMsg = ($result instanceof \Exception) ? $result->getMessage() : 'Unknown error';
				$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_MAIL_SEND_ERROR') . ': ' . $errorMsg, 'error');
				return false;
			}
		}
		catch (\Exception $e)
		{
			$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_MAIL_SEND_ERROR') . ': ' . $e->getMessage(), 'error');
			return false;
		}

		// Send confirmation email to client if email is provided
		if (!empty($clientEmail) && filter_var($clientEmail, FILTER_VALIDATE_EMAIL))
		{
			try
			{
				$clientMailer = $mailerFactory->createMailer();
				$clientMailer->isHtml(true);
				$clientMailer->setSender([$senderEmail, $senderName]);
				$clientMailer->addRecipient($clientEmail);
				$clientMailer->setSubject(Text::_('PLG_CONTENT_CONTENTCART_ORDER_CONFIRMATION'));
				$clientMailer->setBody($body);

				$clientMailer->send();

				if ($app->get('debug'))
				{
					$app->enqueueMessage('Confirmation email sent to client: ' . $clientEmail, 'success');
				}
			}
			catch (\Exception $e)
			{
				// Client email failure is not critical - log but continue
				if ($app->get('debug'))
				{
					$app->enqueueMessage('Failed to send confirmation to client: ' . $e->getMessage(), 'warning');
				}
			}
		}

		$session    = $app->getSession();
		$categoryId = $params->get('cat_for_orders');

		// Save order as article if category is specified
		if (!empty($categoryId))
		{
			try
			{
				Table::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_content/tables');
				$article = Table::getInstance('Content', 'Joomla\\CMS\\Table\\', ['dbo' => Factory::getDbo()]);

				if (!$article)
				{
					$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_ARTICLE_CREATE_ERROR'), 'error');
					return false;
				}

				// Use current user ID, or find first user if guest
				$createdBy = $user->id;

				if ($createdBy === 0)
				{
					// Guest user - find first existing user
					$db = Factory::getDbo();
					$query = $db->getQuery(true)
						->select($db->quoteName('id'))
						->from($db->quoteName('#__users'))
						->order($db->quoteName('id') . ' ASC');
					$db->setQuery($query, 0, 1);
					$createdBy = (int) $db->loadResult();

					if ($createdBy === 0)
					{
						$createdBy = 1; // Ultimate fallback - should never happen
					}
				}

				$article->title       = Text::_('PLG_CONTENT_CONTENTCART_ORDER') . ' ' . (new Date('now'))->format('d-m-Y H:i:s');
				$article->alias       = ''; // Auto-generate from title
				$article->introtext   = $body;
				$article->fulltext    = '';
				$article->catid       = $categoryId;
				$article->created     = (new Date('now'))->toSql();
				$article->created_by  = $createdBy;
				$article->modified    = (new Date('now'))->toSql();
				$article->modified_by = $createdBy;
				$article->state       = 1; // Published
				$article->access      = 1; // Public
				$article->language    = '*';
				$article->featured    = 0;
				$article->publish_up  = (new Date('now'))->toSql();
				$article->publish_down = null;

				$registry = new Registry();
				$registry->set('page_title', '');
				$registry->set('author', '');
				$registry->set('robots', '');
				$article->metadata = (string) $registry;

				$article->attribs = '{}'; // Empty JSON for article params
				$article->version = 1;

				// Add missing database fields for Joomla 5 compatibility
				$article->metakey = '';
				$article->metadesc = '';
				$article->urls = '{}';
				$article->images = '{}';
				$article->hits = 0;

				if (!$article->check() || !$article->store(true))
				{
					$app->enqueueMessage($article->getError(), 'error');
					return false;
				}

				// Debug: log created article details
				if ($app->get('debug'))
				{
					$db = Factory::getDbo();
					$query = $db->getQuery(true)
						->select('*')
						->from($db->quoteName('#__content'))
						->where($db->quoteName('id') . ' = ' . (int) $article->id);
					$db->setQuery($query);
					$savedArticle = $db->loadObject();

					$app->enqueueMessage('Article created: ID=' . $article->id . ', title=' . $article->title . ', alias=' . $article->alias . ', state=' . $article->state, 'success');

					if ($savedArticle)
					{
						$debugInfo = 'DB Check: state=' . $savedArticle->state
							. ', catid=' . $savedArticle->catid
							. ', access=' . $savedArticle->access
							. ', language=' . $savedArticle->language
							. ', created_by=' . $savedArticle->created_by;
						$app->enqueueMessage($debugInfo, 'info');
					}
					else
					{
						$app->enqueueMessage('WARNING: Article not found in database after save!', 'error');
					}
				}

				// CRITICAL: Assign workflow stage so article appears in admin panel
				// In Joomla 4/5, articles MUST be assigned to a workflow stage
				if ($article->id)
				{
					try
					{
						$db = Factory::getDbo();

						// Get default workflow stage for the article's category
						$query = $db->getQuery(true)
							->select('ws.id, ws.title, ws.workflow_id')
							->from($db->quoteName('#__workflow_stages', 'ws'))
							->join('INNER', $db->quoteName('#__workflows', 'w') . ' ON w.id = ws.workflow_id')
							->where($db->quoteName('w.extension') . ' = ' . $db->quote('com_content.article'))
							->where($db->quoteName('ws.default') . ' = 1')
							->order($db->quoteName('ws.ordering'));
						$db->setQuery($query, 0, 1);
						$defaultStage = $db->loadObject();

						if ($defaultStage)
						{
							// Check if workflow association already exists
							$query = $db->getQuery(true)
								->select('COUNT(*)')
								->from($db->quoteName('#__workflow_associations'))
								->where($db->quoteName('item_id') . ' = ' . (int) $article->id)
								->where($db->quoteName('extension') . ' = ' . $db->quote('com_content.article'));
							$db->setQuery($query);
							$exists = (int) $db->loadResult();

							if (!$exists)
							{
								// Create workflow association
								$workflowAssoc = new \stdClass();
								$workflowAssoc->item_id = (int) $article->id;
								$workflowAssoc->stage_id = (int) $defaultStage->id;
								$workflowAssoc->extension = 'com_content.article';

								$db->insertObject('#__workflow_associations', $workflowAssoc);

								if ($app->get('debug'))
								{
									$app->enqueueMessage('Workflow stage assigned: stage_id=' . $defaultStage->id . ' (' . $defaultStage->title . ') for article ' . $article->id, 'success');
								}
							}
							else if ($app->get('debug'))
							{
								$app->enqueueMessage('Workflow association already exists for article ' . $article->id, 'info');
							}

							// Verify workflow association
							if ($app->get('debug'))
							{
								$query = $db->getQuery(true)
									->select('wa.stage_id, ws.title as stage_title')
									->from($db->quoteName('#__workflow_associations', 'wa'))
									->join('LEFT', $db->quoteName('#__workflow_stages', 'ws') . ' ON ws.id = wa.stage_id')
									->where($db->quoteName('wa.item_id') . ' = ' . (int) $article->id)
									->where($db->quoteName('wa.extension') . ' = ' . $db->quote('com_content.article'));
								$db->setQuery($query);
								$workflowAssoc = $db->loadObject();

								if ($workflowAssoc)
								{
									$app->enqueueMessage('Workflow association verified: stage="' . $workflowAssoc->stage_title . '" (ID: ' . $workflowAssoc->stage_id . ')', 'success');
								}
								else
								{
									$app->enqueueMessage('ERROR: No workflow association found in database for article ' . $article->id, 'error');
								}
							}
						}
						else
						{
							if ($app->get('debug'))
							{
								$app->enqueueMessage('WARNING: No default workflow stage found for com_content.article', 'warning');
							}
						}
					}
					catch (\Exception $e)
					{
						// Don't fail the whole order if workflow fails
						$app->enqueueMessage('Workflow assignment failed: ' . $e->getMessage(), 'warning');
					}
				}
			}
			catch (\Exception $e)
			{
				$app->enqueueMessage($e->getMessage(), 'error');
				return false;
			}
		}

		// Clear cart and redirect
		$session->clear('content_order');
		$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_ORDER_ACCEPTED'));

		// Add flag to URL to trigger localStorage clearing via JavaScript
		$currentUri = Uri::getInstance();
		$currentUri->setVar('order_success', '1');
		$app->redirect($currentUri->toString());

		return true;
	}
}
