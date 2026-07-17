<?php

declare(strict_types=1);

namespace ParadoxLabs\CloverHyvaCheckout\Test\Unit\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Success;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Payment\DefaultOrderData;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magewirephp\Magewire\Component;
use ParadoxLabs\CloverHyvaCheckout\Magewire\Payment\PlaceOrderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the Hyva Checkout PlaceOrderService
 */
class PlaceOrderServiceTest extends TestCase
{
    private PlaceOrderService $service;
    private CartManagementInterface|MockObject $cartManagement;
    private Quote|MockObject $quote;
    private QuotePayment|MockObject $payment;

    protected function setUp(): void
    {
        $this->cartManagement = $this->createMock(CartManagementInterface::class);
        $this->payment = $this->createMock(QuotePayment::class);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->quote->method('getId')->willReturn(7);

        $this->service = new PlaceOrderService(
            $this->cartManagement,
            new DefaultOrderData(),
        );
    }

    public function testPlaceOrderImportsWhitelistedPaymentData(): void
    {
        $clientPayload = [
            'token' => 'clv_source_abc123',
            'card_id' => '',
            'cc_type' => 'VISA',
            'cc_exp_year' => '2030',
            'cc_exp_month' => '12',
            'cc_last4' => '1111',
            'cc_bin' => '411111',
            'save' => 1,
        ];

        $this->service->getData()->setData(['payment' => $clientPayload]);

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_clover',
                PaymentInterface::KEY_ADDITIONAL_DATA => $clientPayload,
            ]);

        // The raw client keys must be re-staged onto the payment so the second importData()
        // inside QuoteManagement::placeOrder() replays the same data instead of wiping
        // cc_last_4/cc_type/cc_bin (see PlaceOrderService docblock).
        $this->payment->expects($this->once())
            ->method('addData')
            ->with($clientPayload);

        $this->cartManagement->expects($this->once())
            ->method('placeOrder')
            ->with(7, $this->payment)
            ->willReturn('42');

        $this->assertSame(42, $this->service->placeOrder($this->quote));
    }

    public function testPlaceOrderStripsUnknownKeysAndForcesMethod(): void
    {
        $this->service->getData()->setData([
            'payment' => [
                'method' => 'checkmo',
                'token' => 'clv_source_abc123',
                'tokenbase_id' => '999',
                'cc_number' => '4111111111111111',
                'transaction_id' => 'txn-evil',
                'save' => 0,
            ],
        ]);

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_clover',
                PaymentInterface::KEY_ADDITIONAL_DATA => [
                    'token' => 'clv_source_abc123',
                    'save' => 0,
                ],
            ]);

        $this->payment->expects($this->once())
            ->method('addData')
            ->with([
                'token' => 'clv_source_abc123',
                'save' => 0,
            ]);

        $this->cartManagement->method('placeOrder')->willReturn('100');

        $this->assertSame(100, $this->service->placeOrder($this->quote));
    }

    public function testPlaceOrderPassesStoredCard(): void
    {
        $this->service->getData()->setData([
            'payment' => [
                'card_id' => 'a1b2c3hash',
                'save' => 1,
            ],
        ]);

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_clover',
                PaymentInterface::KEY_ADDITIONAL_DATA => [
                    'card_id' => 'a1b2c3hash',
                    'save' => 1,
                ],
            ]);

        $this->cartManagement->method('placeOrder')->willReturn('55');

        $this->assertSame(55, $this->service->placeOrder($this->quote));
    }

    public function testPlaceOrderWithNoPaymentDataImportsEmptyAdditionalData(): void
    {
        $this->service->getData()->setData([]);

        $this->payment->expects($this->once())
            ->method('importData')
            ->with([
                PaymentInterface::KEY_METHOD => 'paradoxlabs_clover',
                PaymentInterface::KEY_ADDITIONAL_DATA => [],
            ]);

        $this->cartManagement->method('placeOrder')->willReturn('5');

        $this->assertSame(5, $this->service->placeOrder($this->quote));
    }

    public function testHandleExceptionDoesNotRethrow(): void
    {
        // Rethrowing would route through Magewire's effect-less exception response, dropping the
        // order:place:*:error browser events and (in production) the decline message.
        $component = $this->createMock(Component::class);

        $this->service->handleException(
            new LocalizedException(__('Your card was declined.')),
            $component,
            $this->quote
        );

        $this->assertFalse($this->service->canRedirect());
    }

    public function testEvaluateCompletionAfterFailureReturnsLocalizedErrorMessage(): void
    {
        $component = $this->createMock(Component::class);
        $this->service->handleException(
            new LocalizedException(__('Your card was declined.')),
            $component,
            $this->quote
        );

        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->expects($this->once())
            ->method('withMessage')
            ->with('Your card was declined.')
            ->willReturnSelf();

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);

        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory, null));
    }

    public function testEvaluateCompletionAfterFailureMasksNonLocalizedExceptionMessage(): void
    {
        $component = $this->createMock(Component::class);
        $this->service->handleException(
            new RuntimeException('SQLSTATE[23000]: something internal'),
            $component,
            $this->quote
        );

        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->expects($this->once())
            ->method('withMessage')
            ->with('Something went wrong while processing your order. Please try again.')
            ->willReturnSelf();

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);

        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory, null));
    }

    public function testEvaluateCompletionWithMissingOrderIdReturnsErrorMessage(): void
    {
        $errorMessage = $this->createMock(ErrorMessage::class);
        $errorMessage->method('withMessage')->willReturnSelf();

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->expects($this->once())->method('createErrorMessage')->willReturn($errorMessage);

        $this->assertSame($errorMessage, $this->service->evaluateCompletion($resultFactory, null));
    }

    public function testEvaluateCompletionWithOrderIdReturnsSuccess(): void
    {
        $success = $this->createMock(Success::class);

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->expects($this->once())->method('createSuccess')->willReturn($success);

        $this->assertSame($success, $this->service->evaluateCompletion($resultFactory, 42));
        $this->assertTrue($this->service->canRedirect());
    }
}
