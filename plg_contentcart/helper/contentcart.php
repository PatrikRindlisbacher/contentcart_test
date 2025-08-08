<?php
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Registry\Registry;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Workflow\Workflow;

class PlgContentContentcartHelper
{
	public static function sendOrderEmail($params, $content_order)
	{
		if (empty($content_order) || !is_array($content_order))
		{
			// Nothing to process
			return;
		}

		$app = Factory::getApplication();
		$input = $app->input;
		$user = $app->getIdentity();
		
		$mailer = Factory::getMailer();
		$recipient = [$app->get('mailfrom')];
		$mailer->addRecipient($recipient);
		
		$mailer->setSubject(Text::_('PLG_CONTENT_CONTENTCART_ORDER_INFO'));
		
		$layoutData = [
			'params' => $params,
			'content_order' => $content_order,
			'client_name' => $input->getString('client_name'),
			'client_email' => $input->getString('client_email'),
			'client_phone' => $input->getString('client_phone'),
			'client_note' => $input->getString('client_note'),
			'title_note' => $params->get('title_note') ? $params->get('title_note') : Text::_('PLG_CONTENT_CONTENTCART_CLIENT_NOTE'),
			'input' => $input
		];
		
		$body = LayoutHelper::render('plugins.content.contentcart.mail', $layoutData);
		
		$mailer->isHTML(true);
		$mailer->setBody($body);
		
		$send = $mailer->Send();
		$session = $app->getSession();
		
		if ($send !== true)
		{
			$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_MAIL_SEND_ERROR'), 'error');
		}
		else
		{
			$categoryId = $params->get('cat_for_orders');
			if (!empty($categoryId))
			{
				$db = Factory::getDbo();
				$article = Table::getInstance('Content', 'Joomla\\Component\\Content\\Administrator\\Table\\', ['dbo' => $db]);

				$article->title            = Text::_('PLG_CONTENT_CONTENTCART_ORDER') . ' ' . (new Date('now'))->format('d-m-Y H:i:s');
				$article->introtext        = $body;
				$article->catid            = $categoryId;
				$article->created          = (new Date('now'))->toSql();
				$article->created_by	   = $user->id;
				$article->state            = 0;
				$article->access           = 1;

				$registry = new Registry();
				$registry->set('page_title', '');
				$registry->set('author', '');
				$registry->set('robots', '');
				$article->metadata         = (string) $registry;

				$article->language         = '*';

				if (!$article->check() || !$article->store(true)) {
					$app->enqueueMessage($article->getError(), 'error');
					return;
				}

				if ($article->id)
				{
					try
					{
						$workflow = new Workflow('com_content.article', $article->id);
						$defaultStage = $workflow->getDefaultStage($article->catid, $article->language);

						if ($defaultStage)
						{
							$workflow->changeStage($defaultStage->id, Text::_('PLG_CONTENT_PLG_CONTENT_CONTENTCART_WORKFLOW_NEW_ORDER'));
						}
					}
					catch (\Exception $e)
					{
						$app->enqueueMessage($e->getMessage(), 'error');
					}
				}
			}
			$session->clear('content_order');
			$app->enqueueMessage(Text::_('PLG_CONTENT_CONTENTCART_ORDER_ACCEPTED'));
			$app->redirect(Uri::getInstance()->toString());
		}
	}
}