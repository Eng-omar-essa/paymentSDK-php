<?php
/**
 * Shop System SDK - Terms of Use
 *
 * The SDK offered are provided free of charge by Wirecard AG and are explicitly not part
 * of the Wirecard AG range of products and services.
 *
 * They have been tested and approved for full functionality in the standard configuration
 * (status on delivery) of the corresponding shop system. They are under General Public
 * License Version 3 (GPLv3) and can be used, developed and passed on to third parties under
 * the same terms.
 *
 * However, Wirecard AG does not provide any guarantee or accept any liability for any errors
 * occurring when used in an enhanced, customized shop system configuration.
 *
 * Operation in an enhanced, customized configuration is at your own risk and requires a
 * comprehensive test phase by the user of the plugin.
 *
 * Customers use the SDK at their own risk. Wirecard AG does not guarantee their full
 * functionality neither does Wirecard AG assume liability for any disadvantages related to
 * the use of the SDK. Additionally, Wirecard AG does not guarantee the full functionality
 * for customized shop systems or installed SDK of other vendors of plugins within the same
 * shop system.
 *
 * Customers are responsible for testing the SDK's functionality before starting productive
 * operation.
 *
 * By installing the SDK into the shop system the customer agrees to these terms of use.
 * Please do not use the SDK if you do not agree to these terms of use!
 */

namespace WirecardTest\PaymentSdk;

use Psr\Log\LoggerInterface;
use Wirecard\PaymentSdk\Config\Config;
use Wirecard\PaymentSdk\Config\CreditCardConfig;
use Wirecard\PaymentSdk\Config\PaymentMethodConfig;
use Wirecard\PaymentSdk\Entity\Amount;
use Wirecard\PaymentSdk\Exception\MalformedResponseException;
use Wirecard\PaymentSdk\Response\FailureResponse;
use Wirecard\PaymentSdk\Response\FormInteractionResponse;
use Wirecard\PaymentSdk\Response\SuccessResponse;
use Wirecard\PaymentSdk\Transaction\PayPalTransaction;
use Wirecard\PaymentSdk\Transaction\RatepayDirectDebitTransaction;
use Wirecard\PaymentSdk\Transaction\RatepayInvoiceTransaction;
use Wirecard\PaymentSdk\Transaction\RatepayTransaction;
use Wirecard\PaymentSdk\TransactionService;

/**
 * Class TransactionServiceUTest
 * @package WirecardTest\PaymentSdk
 */
class TransactionServiceUTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @var TransactionService $service
     */
    private $service;

    public function setUp()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new Config('https://api-test.wirecard.com', 'user', 'password');
        $ccardConfig = new CreditCardConfig('maid', 'secret');
        $ccardConfig->setThreeDCredentials('3dmaid', '3dsecret');
        $ccardConfig->addSslMaxLimit(new Amount(100, 'EUR'));
        $ccardConfig->addThreeDMinLimit(new Amount(50, 'EUR'));
        $config->add($ccardConfig);
        $this->service = new TransactionService($config, $logger);
    }

    public function testGetDataFor3dCreditCardUi()
    {
        $uiData = $this->service->getDataForCreditCardUi('en', new Amount(300, 'EUR'));

        $expected = [
            'transaction_type' => 'authorization',
            'merchant_account_id' => '3dmaid',
            'requested_amount' => 300,
            'requested_amount_currency' => 'EUR',
            'locale' => 'en',
            'payment_method' => 'creditcard',
            'attempt_three_d' => true
        ];

        $uiData = (array)json_decode($uiData);
        unset($uiData['request_time_stamp'], $uiData['request_id'], $uiData['request_signature']);

        $this->assertEquals($expected, $uiData);
    }

    public function testMapJsResponseThreeD()
    {
        $url = 'dummyreturnurl';
        $data = array(
            'merchant_account_id' => 'maid',
            'transaction_id' => 'trid',
            'transaction_state' => 'success',
            'transaction_type' => 'authorization',
            'payment_method' => 'creditcard',
            'request_id' => 'reqid',
            'status_code_0' => '201.000',
            'status_description_0' => 'Dummy status description',
            'status_severity_0' => 'information',
            'acs_url' => 'http://dummy.acs.url',
            'pareq' => 'testpareq',
            'cardholder_authentication_status' => 'Y',
            'parent_transaction_id' => 'ptrid'
        );

        $response = $this->service->processJsResponse($data, $url);
        $this->assertTrue($response instanceof FormInteractionResponse);
    }

    public function testMapJsResponseSSL()
    {
        $url = 'dummyreturnurl';
        $data = array(
            'merchant_account_id' => 'maid',
            'transaction_id' => 'trid',
            'transaction_state' => 'success',
            'transaction_type' => 'authorization',
            'payment_method' => 'creditcard',
            'request_id' => 'reqid',
            'status_code_0' => '201.000',
            'status_description_0' => 'Dummy status description',
            'status_severity_0' => 'information',
            'parent_transaction_id' => 'ptrid'
        );

        $response = $this->service->processJsResponse($data, $url);
        $this->assertTrue($response instanceof SuccessResponse);
    }

    public function testMapJsResponseSSLFailed()
    {
        $url = 'dummyreturnurl';
        $data = array(
            'merchant_account_id' => 'maid',
            'transaction_id' => 'trid',
            'transaction_state' => 'failed',
            'transaction_type' => 'authorization',
            'payment_method' => 'creditcard',
            'request_id' => 'reqid',
            'status_code_0' => '500.000',
            'status_description_0' => 'Dummy status description',
            'status_severity_0' => 'information',
            'parent_transaction_id' => 'ptrid'
        );

        $response = $this->service->processJsResponse($data, $url);
        $this->assertTrue($response instanceof FailureResponse);
    }

    public function testConstructorWithRequestIdGenerator()
    {
        $logger = $this->createMock(LoggerInterface::class);
        $config = new Config('https://api-test.wirecard.com', 'user', 'password');
        $paypalConfig = new PaymentMethodConfig(PayPalTransaction::NAME, 'maid', 'secret');
        $config->add($paypalConfig);

        $requestIdGenerator = function () {
            return 'request id';
        };

        $service = new TransactionService($config, $logger, null, null, $requestIdGenerator);

        $response = $service->handleNotification('
            <xml>
                <transaction-state>success</transaction-state>
                <payment-methods><payment-method value="ccard"/></payment-methods>
                <statuses></statuses>
                <request-id>request id</request-id>
                <transaction-id>transaction id</transaction-id>
                <transaction-type>purchase</transaction-type>
            </xml>');

        $this->assertTrue($response instanceof SuccessResponse);
    }

    public function testHandleResponseCheckEnrollment()
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';

        $this->expectException(MalformedResponseException::class);

        $this->service->handleResponse(['MD' => 'md', 'PaRes' => 'pares']);
    }
}
