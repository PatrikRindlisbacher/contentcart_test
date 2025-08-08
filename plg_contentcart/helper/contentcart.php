<?php
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;

class PlgContentContentcartHelper
{
	public static function sendOrderEmail($params, $content_order)
	{
		$app = Factory::getApplication();
		$input = $app->input;
		$user = $app->getIdentity();
		
		$mailer = Factory::getMailer();
		$recipient = [$app->get('mailfrom')];
		$mailer->addRecipient($recipient);
		
		$mailer->setSubject(Text::_('CONTENTCART_ORDER_INFO'));
		
		$layoutData = [
			'params' => $params,
			'content_order' => $content_order,
			'client_name' => $input->getString('client_name'),
			'client_email' => $input->getString('client_email'),
			'client_phone' => $input->getString('client_phone'),
			'client_note' => $input->getString('client_note'),
			'title_note' => $params->get('title_note') ? $params->get('title_note') : Text::_('CONTENTCART_CLIENT_NOTE'),
			'input' => $input
		];
		
		$body = LayoutHelper::render('plugins.content.contentcart.mail', $layoutData);
		
		$mailer->isHTML(true);
		$mailer->setBody($body);
		
		$send = $mailer->Send();
		$session = $app->getSession();
		
		if ($send !== true)
		{
			$app->enqueueMessage(Text::_('CONTENTCART_MAIL_SEND_ERROR'), 'error');
		}
		else
		{
			$categoryId = $params->get('cat_for_orders');
			if (!empty($categoryId))
			{
				$article = \Joomla\CMS\Table\Table::getInstance('Content');
				$article->title            = Text::_('CONTENTCART_ORDER').' '.date( 'd-m-Y H:i:s' );
				$article->introtext        = $body;
				$article->catid            = $categoryId;
				$article->created          = Factory::getDate()->toSQL();
				$article->created_by	   = $user->id;
				$article->state            = 0;
				$article->access           = 1;
				$article->metadata         = '{"page_title":"","author":"","robots":""}';
				$article->language         = '*';
				
				if (!$article->check() || !$article->store(true)) {
					$app->enqueueMessage($article->getError(), 'error');
					return;
				}
				
				if ($article->id) {
					$workflow = new \Joomla\CMS\Workflow\Workflow('com_content.article');
					try {
						$stage_id = $workflow->getDefaultStageByCategory($categoryId);
						if ($stage_id) {
							$workflow->createAssociation($article->id, $stage_id);
						}
					} catch (\Exception $e) {
						$app->enqueueMessage($e->getMessage(), 'error');
						return;
					}
				}
			}
			$session->clear('content_order');
			$app->enqueueMessage(Text::_('CONTENTCART_ORDER_ACCEPTED'));
			$app->redirect(Uri::getInstance()->toString());
		}
	}
}