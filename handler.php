<?php
namespace Sale\Handlers\PaySystem;

use Bitrix\Main\Error;
use Bitrix\Main\Request;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PriceMaths;

Loc::loadMessages(__FILE__);

class BankrossiiHandler extends PaySystem\ServiceHandler implements PaySystem\IRefundExtended
{
	var $redirectUrl = '';

	/* ОПЛАТ ЗАКАЗА */

	/* основная функция */
	public function initiatePay(Payment $payment, Request $request = null)
	{
		// запрос на создание заказа в системе банка
		if ($result = $this->orderCreate($payment)) {
			// параметры для вывода в шаблоне
			$this->setExtraParams( array(
				'SUMM' => $result['SUMM'],
				'LINK' => $result['LINK'],
				'ERROR' => $result['ERROR']
			) );
		}
		// подключаем шаблон
		return $this->showTemplate($payment, "template");
	}

	/* создаем заказ в банке */
	protected function orderCreate(Payment $payment)
	{
		$xmlRequest = $this->xmlOrderCreate(
			$this->getMerchant($payment),
			$this->getBitrixOrderId($payment),
			$this->getSummFromPayment($payment),
			$this->getPsResult().'?action=pay_confirm',
			$this->getPsResult().'?action=pay_cancel',
			$this->getPsResult().'?action=pay_decline'
		);
		$xmlResponse = $this->sendCurl($xmlRequest);
		$xml = simplexml_load_string($xmlResponse);

		if ( $xml->Response->Status != "00" ) {
			PaySystem\ErrorLog::add(array(
				'ACTION' => 'orderCreate',
				'MESSAGE' => 'Invalid response status: '.$xml->Response->Status
			));
			return array(
				'SUMM'  => false,
				'LINK'  => false,
				'ERROR' => 'Invalid response status: '.$xml->Response->Status
			);
		} else {
			$ORDERID   = (string)$xml->Response->Order->OrderID;
			$SESSIONID = (string)$xml->Response->Order->SessionID;
			$payment->setField('PS_STATUS_DESCRIPTION', json_encode(array(
				'ORDERID' => $ORDERID,
				'SESSIONID' => $SESSIONID
			)));
			$payment->save();

			$urlList = $this->getUrlList();
			return array(
				'SUMM'  => $this->getSummFromPayment($payment, false),
				'LINK'  => $urlList['payUrl'].'?ORDERID='.$ORDERID.'&SESSIONID='.$SESSIONID,
				'ERROR' => false
			);
		}
	}

	/* ВОЗВРАТ СРЕДСТВ */

	/* поддерживаем возврат средств? */
	public function isRefundableExtended()
	{
		return true;
	}

	/* обработчик возврата */
	public function refund(Payment $payment, $refundableSum)
	{
		$result = new PaySystem\ServiceResult();

		$savedData = json_decode($payment->getField('PS_STATUS_DESCRIPTION'), true);
		// если инфа по заказу есть отправляем запрос на проверку статуса
		if ($savedData['ORDERID'] && $savedData['SESSIONID']) {
			$xmlRequest = $this->xmlOrderRefund(
				$this->getMerchant($payment),
				$savedData['ORDERID'],
				$savedData['SESSIONID'],
				$this->formatSumm($refundableSum)
			);
			$xmlResponse = $this->sendCurl($xmlRequest);
			$xml = simplexml_load_string($xmlResponse);

			if ( $xml->Response->Status != "00" ) {
				PaySystem\ErrorLog::add(array(
					'ACTION' => 'refund',
					'MESSAGE' => 'Invalid response status: '.$xml->Response->Status
				));
				$result->addError(new Error('Invalid response status: '.$xml->Response->Status));
			} else {
				$payment->setField('PS_STATUS_DESCRIPTION', '');
				$payment->save();
				$result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
			}

			return $result;
		}
	}

	/* обработчик отмены */
	public function cancel(Payment $payment)
	{
		$result = new PaySystem\ServiceResult();

		$savedData = json_decode($payment->getField('PS_STATUS_DESCRIPTION'), true);
		// если инфа по заказу есть отправляем запрос на проверку статуса
		if ($savedData['ORDERID'] && $savedData['SESSIONID']) {
			$xmlRequest = $this->xmlOrderCancel(
				$this->getMerchant($payment),
				$savedData['ORDERID'],
				$savedData['SESSIONID']
			);
			$xmlResponse = $this->sendCurl($xmlRequest);
			$xml = simplexml_load_string($xmlResponse);

			if ( $xml->Response->Status != "00" ) {
				PaySystem\ErrorLog::add(array(
					'ACTION' => 'cancel',
					'MESSAGE' => 'Invalid response status: '.$xml->Response->Status
				));
				$result->addError(new Error('Invalid response status: '.$xml->Response->Status));
			} else {
				$payment->setField('PS_STATUS_DESCRIPTION', '');
				$payment->save();
				$result->setOperationType(PaySystem\ServiceResult::MONEY_LEAVING);
			}

			return $result;
		}
	}

	/* ВОЗВРАТ СО СТРАНИЦЫ БАНКА */

	/* 0 функция из ответа банка достает ид платежки */
	public function getPaymentIdFromRequest(Request $request)
	{
		$bankOrderId = simplexml_load_string(base64_decode($request->get('xmlmsg')));
		$bankOrderId = intval($bankOrderId->OrderID);
		// ищем нужную платежку по ид заказа из системы банка
		$res = Payment::getList( array(
			'filter' => array(
				'PS_STATUS_DESCRIPTION' => '%'.$bankOrderId.'%'
			)
		) );
		while ($item = $res->fetch()) {
			$data = json_decode($item['PS_STATUS_DESCRIPTION'], true);
			if ($data['ORDERID'] == $bankOrderId) {
				return $item['ID'];
			}
		}
		return false;
	}

	/* 1 список параметров, которые возвращает банк, чтобы проверить что надо выполнить именно этот обработчик ПС */
	public static function getIndicativeFields()
	{
		return array('xmlmsg');
	}

	/* 2 доп проверки (ничего не проверяем) */
	static protected function isMyResponseExtended(Request $request, $paySystemId)
	{
		return true;
	}

	/* 3 обрабатывает результат от банка */
	public function processRequest(Payment $payment, Request $request)
	{
		$getAction = '';
		$result = new PaySystem\ServiceResult();
		$action = $request->get('action');
		$backUrlList = $this->getBackUrlList($payment);
		// успешная оплата
		if ($action == 'pay_confirm') {
			$status = $this->getOrderStatus($payment);
			// точно успешная
			if ($status == 'APPROVED') {
				$fields = array(
					"PS_STATUS" => "Y",
					"PS_STATUS_CODE" => $status,
					"PS_RESPONSE_DATE" => new DateTime(),
				);
				$result->setOperationType(PaySystem\ServiceResult::MONEY_COMING);
				$this->redirectUrl = $backUrlList['confirm'];
			}
			// не успешная
			else {
				PaySystem\ErrorLog::add(array(
					'ACTION' => 'processRequest',
					'MESSAGE' => base64_decode($_REQUEST['xmlmsg'])
				));
				$result->addError(new Error('Decline'));
				$this->redirectUrl = $backUrlList['decline'];
			}
		}
		// пользователь отказался от оплаты
		if ($action == 'pay_cancel') {
			$this->redirectUrl = $backUrlList['cancel'];
		}
		// отказ банка в оплате
		if ($action == 'pay_decline') {
			PaySystem\ErrorLog::add(array(
				'ACTION' => 'processRequest',
				'MESSAGE' => base64_decode($_REQUEST['xmlmsg'])
			));
			$result->addError(new Error('Decline'));
			$this->redirectUrl = $backUrlList['decline'];
		}
		$result->setPsData($fields);
		return $result;
	}

	/* проверяем статус заказа в банке */
	protected function getOrderStatus(Payment $payment)
	{
		$result = false;
		$savedData = json_decode($payment->getField('PS_STATUS_DESCRIPTION'), true);
		// если инфа по заказу есть отправляем запрос на проверку статуса
		if ($savedData['ORDERID'] && $savedData['SESSIONID']) {
			$xmlRequest = $this->xmlOrderStatus(
				$this->getMerchant($payment),
				$savedData['ORDERID'],
				$savedData['SESSIONID']
			);
			$xmlResponse = $this->sendCurl($xmlRequest);
			$xml = simplexml_load_string($xmlResponse);
			$result = (string)$xml->row->Orderstatus;
		}
		return $result;
	}

	public function sendResponse(PaySystem\ServiceResult $result, Request $request)
	{
		if ($this->redirectUrl) {
			LocalRedirect($this->redirectUrl);
		}
	}

	/* ГЕНЕРАЦИЯ XML ДЛЯ ЗАПРОСОВ и функция отправки */

	/* формируем запрос на регистрацию заказа */
	protected function xmlOrderCreate($merchant, $bitrixOrderId, $summ, $urlConfirm, $urlCancel, $urlDecline)
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
		     . '<TKKPG>'
		     .   '<Request>'
		     .     '<Operation>CreateOrder</Operation>'
		     .     '<Language>RU</Language>'
		     .     '<Order>'
		     .       '<OrderType>Purchase</OrderType>'
		     .       '<Merchant>' . $merchant . '</Merchant>'
		     .       '<Amount>' . $summ . '</Amount>'
		     .       '<Currency>643</Currency>'
		     .       '<Description>Оплата заказа ' . $bitrixOrderId . '</Description>'
		     .       '<ApproveURL>' . $urlConfirm . '</ApproveURL>'
		     .       '<CancelURL>' . $urlCancel . '</CancelURL>'
		     .       '<DeclineURL>' . $urlDecline . '</DeclineURL>'
		     .       '<AddParams>'
		     .         '<DescriptionHtml>Оплата заказа ' . $bitrixOrderId . '</DescriptionHtml>'
		     .       '</AddParams>'
		     .     '</Order>'
		     .   '</Request>'
		     . '</TKKPG>';
	}

	/* формируем запрос на получение статуса заказа */
	protected function xmlOrderStatus($merchant, $ORDERID, $SESSIONID)
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
		     . '<TKKPG>'
		     .   '<Request>'
		     .     '<Operation>GetOrderInformation</Operation>'
		     .     '<Language>RU</Language>'
		     .     '<Order>'
		     .       '<Merchant>' . $merchant . '</Merchant>'
		     .       '<OrderID>' . $ORDERID . '</OrderID>'
		     .     '</Order>'
		     .     '<SessionID>' . $SESSIONID . '</SessionID>'
		     .     '<ShowParams>true</ShowParams>'
		     .     '<ShowOperations>true</ShowOperations>'
		     .   '</Request>'
		     . '</TKKPG>';
	}

	/* формируем запрос на возврат */
	protected function xmlOrderRefund($merchant, $ORDERID, $SESSIONID, $summ)
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
		     . '<TKKPG>'
		     .   '<Request>'
		     .     '<Operation>Refund</Operation>'
		     .     '<Language>RU</Language>'
		     .     '<Order>'
		     .       '<Merchant>' . $merchant . '</Merchant>'
		     .       '<OrderID>' . $ORDERID . '</OrderID>'
		     .     '</Order>'
		     .     '<SessionID>' . $SESSIONID . '</SessionID>'
		     .     '<Refund>'
			 .       '<Amount>'.$summ.'</Amount>'
			 .       '<Currency>643</Currency>'
			 .     '</Refund>'
		     .   '</Request>'
		     . '</TKKPG>';
	}

	/* формируем запрос на отмену */
	protected function xmlOrderCancel($merchant, $ORDERID, $SESSIONID)
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
		     . '<TKKPG>'
		     .   '<Request>'
		     .     '<Operation>Reverse</Operation>'
		     .     '<Language>RU</Language>'
		     .     '<Order>'
		     .       '<Merchant>' . $merchant . '</Merchant>'
		     .       '<OrderID>' . $ORDERID . '</OrderID>'
		     .     '</Order>'
		     .     '<SessionID>' . $SESSIONID . '</SessionID>'
		     .   '</Request>'
		     . '</TKKPG>';
	}

	/* функция отправки запроса в банк */
	function sendCurl($xmlRequest)
	{
		$urlList = $this->getUrlList();
		$cerList = $this->getSertificatesList();
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $urlList['execUrl']);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_SSLVERSION , 6);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,0);
		curl_setopt($ch, CURLOPT_CAINFO, $cerList['SSLCA']);
		curl_setopt($ch, CURLOPT_SSLCERT, $cerList['SSLCert']);
		curl_setopt($ch, CURLOPT_SSLKEY, $cerList['SSLPrivateKey']);
		$result = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		if (curl_errno($ch) || $httpCode != 200) return false;
		curl_close($ch);
		return $result;
	}

	/* HELPERS */

	/* тестовый режим? */
	protected function isTest(Payment $payment = null)
	{
		return ($this->getBusinessValue($payment, 'IS_TEST') == 'Y');
	}

	/* url для запросов и страницы оплаты */
	protected function getUrlList()
	{
		if ($this->isTest()) {
			return array(
				'execUrl' => 'https://pgtest.abr.ru:4443/exec',
				'payUrl'  => 'https://pgtest.abr.ru:443/index.jsp'
			);
		} else {
			return array(
				'execUrl' => 'https://pg.abr.ru:4443/exec',
				'payUrl'  => 'https://pg.abr.ru:443/index.jsp'
			);
		}
	}

	/* пути до сертификатов */
	protected function getSertificatesList()
	{
		if ($this->isTest()) {
			return array(
				'SSLCA'         => __DIR__.'/certificates/tech/CAcert.pem',
				'SSLCert'       => __DIR__.'/certificates/tech/cert.pem',
				'SSLPrivateKey' => __DIR__.'/certificates/tech/private.key'
			);
		} else {
			return array(
				'SSLCA'         => __DIR__.'/certificates/work/CAcert.pem',
				'SSLCert'       => __DIR__.'/certificates/work/cert.pem',
				'SSLPrivateKey' => __DIR__.'/certificates/work/private.key'
			);
		}
	}

	/* merchant */
	protected function getMerchant(Payment $payment)
	{
		return $this->getBusinessValue($payment, 'MERCHANT');
	}

	/* bitrix order id */
	protected function getBitrixOrderId(Payment $payment)
	{
		return intval($payment->getField('ORDER_ID'));
	}

	/* сумма для оплаты (в формате для банка или нет) */
	protected function getSummFromPayment(Payment $payment, $forBank = true)
	{
		$summ = $this->getBusinessValue($payment, 'PAYMENT_SHOULD_PAY');
		if ($forBank) {
			return $this->formatSumm($summ);
		} else {
			return $summ;
		}
	}

	/* форматируем сумму для банка (100руб == 10000) */
	protected function formatSumm($summ)
	{
		return number_format(floatval($summ), 2, '.', '') * 100;
	}

	/* адрес страниц, где размещен обработчик результата от ПС */
	protected function getPsResult()
	{
		return $this->getSiteUrl().'/bitrix/tools/sale_ps_result.php';
	}

	/* адрес сайта */
	protected function getSiteUrl()
	{
		$protocol = isset($_SERVER["HTTPS"]) ? 'https' : 'http';
		return $protocol.'://'.$_SERVER['HTTP_HOST'];
	}

	/* адреса для редиректов при возврате из банка */
	protected function getBackUrlList(Payment $payment)
	{
		$bitrixOrderId = $this->getBitrixOrderId($payment);
		$site = $this->getSiteUrl();
		$confirm = $this->getBusinessValue($payment, 'URL_CONFIRM');
		$confirm = str_replace('#ORDER_ID#', $bitrixOrderId, $confirm);
		$cancel = $this->getBusinessValue($payment, 'URL_CANCEL');
		$cancel = str_replace('#ORDER_ID#', $bitrixOrderId, $cancel);
		$decline = $this->getBusinessValue($payment, 'URL_DECLINE');
		$decline = str_replace('#ORDER_ID#', $bitrixOrderId, $decline);
		return array(
			'confirm' => $confirm,
			'cancel' => $cancel,
			'decline' => $decline
		);
	}

	/* OTHER */

	private function log($str)
	{
		file_put_contents(__DIR__.'/log.txt', $str."\r\n", FILE_APPEND);
	}

	/* возвращаем список валют (без нее не работает) */
	public function getCurrencyList()
	{
		return array('RUB');
	}
}