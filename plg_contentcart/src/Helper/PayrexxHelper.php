<?php
/**
 * Minimal Payrexx helper for ContentCart.
 *
 * Ziel:
 * - Pending Order lokal speichern
 * - Payrexx Checkout erzeugen
 * - Webhook verarbeiten
 * - Nach bestätigter Zahlung Bestellmail senden
 *
 * Hinweis:
 * Diese Minimalversion speichert Orders als JSON-Dateien im Joomla-Cache.
 * Für Produktion ist später eine DB-Tabelle sauberer.
 */

namespace Joomline\Plugin\Content\Contentcart\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Input\Input;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

final class PayrexxHelper
{
	/**
	 * Erzeugt eine Pending Order aus aktuellem Formular + Warenkorb.
	 *
	 * @param   Registry  $params         Plugin-Parameter
	 * @param   array     $contentOrder   Warenkorb
	 *
	 * @return  array
	 */
	public static function createPendingOrder(Registry $params, array $contentOrder): array
	{
		$app   = Factory::getApplication();
		$input = $app->getInput();

		$order = [
			'reference_id'  => 'CC' . date('YmdHis') . rand(1000, 9999),
			'status'        => 'pending_payment',
			'created_at'    => date('c'),
			'currency'      => $params->get('currency', 'CHF'),
			'items'         => $contentOrder,
			'client_name'   => trim((string) $input->getString('client_name')),
			'client_email'  => trim((string) $input->getString('client_email')),
			'client_phone'  => trim((string) $input->getString('client_phone')),
			'client_note'   => trim((string) $input->getString('client_note')),
			'payrexx_gateway_id' => null,
			'payrexx_transaction_id' => null,
		];

		$totalMinor = 0;

		foreach ($contentOrder as $item)
		{
			$price = (float) ($item['price'] ?? 0);
			$count = (int) ($item['count'] ?? 1);

			// Payrexx arbeitet mit Minor Units:
			// CHF 10.50 => 1050
			$totalMinor += (int) round($price * 100) * $count;
		}

		$order['amount_minor'] = $totalMinor;

		self::saveOrder($order);

		return $order;
	}

	/**
	 * Erzeugt einen Payrexx Checkout und gibt die Redirect-URL zurück.
	 *
	 * Voraussetzung:
	 * Die Payrexx PHP Library muss im Joomla-System verfügbar sein.
	 *
	 * @param   Registry  $params  Plugin-Parameter
	 * @param   array     $order   Pending Order (wird ergänzt)
	 *
	 * @return  string
	 */
	public static function createCheckout(Registry $params, array &$order): string
	{
		if (!class_exists(\Payrexx\Payrexx::class))
		{
			throw new \RuntimeException('Payrexx SDK not found.');
		}

		$instance = (string) $params->get('payrexx_instance');
		$secret   = (string) $params->get('payrexx_secret');

		if ($instance === '' || $secret === '')
		{
			throw new \RuntimeException('Payrexx instance or secret missing.');
		}

		$successUrl = Uri::root() . 'index.php?cart=1&payrexx_status=success&order_ref=' . rawurlencode($order['reference_id']);
		$cancelUrl  = Uri::root() . 'index.php?cart=1&payrexx_status=cancel&order_ref=' . rawurlencode($order['reference_id']);
		$webhookUrl = Uri::root() . 'index.php?contentcart_task=payrexx_webhook';

		$payrexx = new \Payrexx\Payrexx($instance, $secret);
		$gateway = new \Payrexx\Models\Request\Gateway();

		$gateway->setAmount((int) $order['amount_minor']);
		$gateway->setCurrency((string) $order['currency']);
		$gateway->setReferenceId((string) $order['reference_id']);
		$gateway->setPurpose('ContentCart Order ' . $order['reference_id']);

		// Redirect-Ziele für Browser
		$gateway->setSuccessRedirectUrl($successUrl);
		$gateway->setCancelRedirectUrl($cancelUrl);
		$gateway->setFailedRedirectUrl($cancelUrl);

		// Manche Payrexx-Versionen unterstützen setWebhookUrl(),
		// andere lösen Webhooks nur über Gateway-Konfiguration aus.
		if (method_exists($gateway, 'setWebhookUrl'))
		{
			$gateway->setWebhookUrl($webhookUrl);
		}

		$response = $payrexx->create($gateway);

		$order['payrexx_gateway_id'] = $response->getId();
		self::saveOrder($order);

		return $response->getLink();
	}

	/**
	 * Verarbeitet den serverseitigen Webhook von Payrexx.
	 *
	 * Ablauf:
	 * - JSON lesen
	 * - Referenz laden
	 * - Transaktion via Payrexx API neu verifizieren
	 * - bei confirmed => paid setzen + Mail senden
	 *
	 * @param   Registry  $params  Plugin-Parameter
	 *
	 * @return  void
	 */
	public static function handleWebhook(Registry $params): void
	{
		if (!class_exists(\Payrexx\Payrexx::class))
		{
			http_response_code(500);
			echo 'Payrexx SDK missing';
			return;
		}

		$payload = json_decode((string) file_get_contents('php://input'), true);

		if (empty($payload['transaction']['referenceId']))
		{
			http_response_code(400);
			echo 'Missing referenceId';
			return;
		}

		$referenceId = (string) $payload['transaction']['referenceId'];
		$order       = self::loadOrder($referenceId);

		if (!$order)
		{
			http_response_code(404);
			echo 'Order not found';
			return;
		}

		$instance = (string) $params->get('payrexx_instance');
		$secret   = (string) $params->get('payrexx_secret');
		$payrexx  = new \Payrexx\Payrexx($instance, $secret);

		// WICHTIG:
		// Webhook-Daten nicht blind vertrauen.
		// Gemäss offiziellem Muster die Transaktion nochmals von Payrexx holen.
		$gateway = new \Payrexx\Models\Request\Gateway();
		$gateway->setId((int) $order['payrexx_gateway_id']);

		$gatewayResponse = $payrexx->getOne($gateway);
		$transactionId   = $gatewayResponse->getInvoices()[0]['transactions'][0]['id'] ?? null;

		if (!$transactionId)
		{
			http_response_code(400);
			echo 'Transaction not found';
			return;
		}

		$transaction = new \Payrexx\Models\Response\Transaction();
		$transaction->setId($transactionId);

		$transactionResponse = $payrexx->getOne($transaction);
		$status              = (string) $transactionResponse->getStatus();

		$order['payrexx_transaction_id'] = $transactionId;
		$order['payrexx_status']         = $status;
		$order['updated_at']             = date('c');

		if ($status === 'confirmed')
		{
			// Doppelte Verarbeitung verhindern
			if (($order['status'] ?? '') !== 'paid')
			{
				$order['status'] = 'paid';
				self::saveOrder($order);
				self::sendStoredOrderEmail($params, $order);
			}

			http_response_code(200);
			echo 'OK';
			return;
		}

		$order['status'] = 'payment_' . $status;
		self::saveOrder($order);

		http_response_code(200);
		echo 'IGNORED';
	}

	/**
	 * Pending Order als JSON speichern.
	 *
	 * @param   array  $order  Order-Daten
	 *
	 * @return  void
	 */
	private static function saveOrder(array $order): void
	{
		$dir = JPATH_CACHE . '/contentcart_payrexx';

		if (!is_dir($dir))
		{
			mkdir($dir, 0755, true);
		}

		file_put_contents(
			$dir . '/' . $order['reference_id'] . '.json',
			json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
		);
	}

	/**
	 * Pending Order laden.
	 *
	 * @param   string  $referenceId  Order-Referenz
	 *
	 * @return  array|null
	 */
	public static function loadOrder(string $referenceId): ?array
	{
		$file = JPATH_CACHE . '/contentcart_payrexx/' . $referenceId . '.json';

		if (!is_file($file))
		{
			return null;
		}

		$data = json_decode((string) file_get_contents($file), true);

		return is_array($data) ? $data : null;
	}

	/**
	 * Sendet die Bestellmail aus der gespeicherten Pending/Paid Order.
	 *
	 * Wichtig:
	 * Diese Methode liest NICHT aus POST, sondern aus der gespeicherten Order.
	 *
	 * @param   Registry  $params  Plugin-Parameter
	 * @param   array     $order   Gespeicherte Order
	 *
	 * @return  void
	 */
	private static function sendStoredOrderEmail(Registry $params, array $order): void
	{
		$app = Factory::getApplication();

		/** @var MailerFactoryInterface $mailerFactory */
		$mailerFactory = Factory::getContainer()->get(MailerFactoryInterface::class);
		$mailer        = $mailerFactory->createMailer();

		$layoutData = [
			'params'        => $params,
			'content_order' => $order['items'],
			'client_name'   => $order['client_name'],
			'client_email'  => $order['client_email'],
			'client_phone'  => $order['client_phone'],
			'client_note'   => $order['client_note'],
			'title_note'    => $params->get('title_note') ?: Text::_('PLG_CONTENT_CONTENTCART_CLIENT_NOTE'),
		];

		$body = LayoutHelper::render(
			'mail',
			$layoutData,
			JPATH_PLUGINS . '/content/contentcart/layouts'
		);

		$mailer->isHtml(true);
		$mailer->setSender([$app->get('mailfrom'), $app->get('fromname')]);
		$mailer->addRecipient($app->get('mailfrom'));
		$mailer->setSubject(Text::_('PLG_CONTENT_CONTENTCART_ORDER_INFO'));
		$mailer->setBody($body);
		$mailer->send();
	}
}