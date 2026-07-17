<?php

declare(strict_types=1);

namespace ParadoxLabs\CloverHyvaCheckout\Test\Unit\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Component\Evaluation\ErrorMessage;
use Hyva\Checkout\Model\Magewire\Component\Evaluation\Validation;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use ParadoxLabs\CloverHyvaCheckout\Magewire\Payment\Clover;
use ParadoxLabs\CloverHyvaCheckout\ViewModel\PaymentForm;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Block\Form\Cc;
use ParadoxLabs\TokenBase\Model\Card;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rakit\Validation\Validator;

/**
 * Unit tests for the Clover Magewire payment component
 */
class CloverTest extends TestCase
{
    private Clover $component;
    private CheckoutSession|MockObject $checkoutSession;
    private CardRepositoryInterface|MockObject $cardRepository;
    private PaymentForm|MockObject $formViewModel;
    private Quote|MockObject $quote;
    private QuotePayment|MockObject $payment;

    protected function setUp(): void
    {
        $this->checkoutSession = $this->createMock(CheckoutSession::class);
        $this->cardRepository = $this->createMock(CardRepositoryInterface::class);
        $this->formViewModel = $this->createMock(PaymentForm::class);

        $this->payment = $this->createMock(QuotePayment::class);

        // getCustomerId is magic (no concrete declaration on Quote); stub getData() and let the
        // real DataObject::__call route it there.
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'getPayment'])
            ->getMock();
        $this->quote->method('getPayment')->willReturn($this->payment);
        $this->checkoutSession->method('getQuote')->willReturn($this->quote);

        $this->component = new Clover(
            new Validator(),
            $this->checkoutSession,
            $this->cardRepository,
            $this->formViewModel,
        );
    }

    public function testMountLoadsStoredCardsFromFormBlock(): void
    {
        $card = $this->buildCard('hash123', 'Visa x-1111', 'VI');

        $formBlock = $this->createMock(Cc::class);
        $formBlock->method('getStoredCards')->willReturn([$card]);

        $this->formViewModel->method('getFormBlock')
            ->with('paradoxlabs_clover')
            ->willReturn($formBlock);

        $this->component->mount();

        $this->assertSame(
            [
                'hash123' => [
                    'hash' => 'hash123',
                    'label' => 'Visa x-1111',
                    'type' => 'VI',
                ],
            ],
            $this->component->storedCards
        );
    }

    public function testBootAddsQuoteAssignedCardOfOwnMethodAndCustomer(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn('5');
        $this->quote->method('getData')->willReturnMap([['customer_id', null, 3]]);

        $card = $this->buildCard('hash456', 'MC x-4444', 'MC', 'paradoxlabs_clover', 3);

        $this->cardRepository->method('getById')->with('5')->willReturn($card);

        $this->component->booted();

        $this->assertArrayHasKey('hash456', $this->component->storedCards);
    }

    public function testBootSkipsCardBelongingToAnotherMethod(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn('5');
        $this->quote->method('getData')->willReturnMap([['customer_id', null, 3]]);

        $card = $this->buildCard('hash456', 'MC x-4444', 'MC', 'paradoxlabs_stripe', 3);

        $this->cardRepository->method('getById')->willReturn($card);

        $this->component->booted();

        $this->assertSame([], $this->component->storedCards);
    }

    public function testBootSkipsCardBelongingToAnotherCustomer(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn('5');
        $this->quote->method('getData')->willReturnMap([['customer_id', null, 3]]);

        $card = $this->buildCard('hash456', 'MC x-4444', 'MC', 'paradoxlabs_clover', 99);

        $this->cardRepository->method('getById')->willReturn($card);

        $this->component->booted();

        $this->assertSame([], $this->component->storedCards);
    }

    public function testBootWithNoAssignedCardNeverQueriesRepository(): void
    {
        $this->payment->method('getData')->with('tokenbase_id')->willReturn(null);

        $this->cardRepository->expects($this->never())->method('getById');

        $this->component->booted();

        $this->assertSame([], $this->component->storedCards);
    }

    public function testEvaluateCompletionRegistersMethodValidator(): void
    {
        $errorMessage = $this->createMock(ErrorMessage::class);
        $validation = $this->createMock(Validation::class);

        $resultFactory = $this->createMock(EvaluationResultFactory::class);
        $resultFactory->method('createErrorMessage')->willReturn($errorMessage);
        $resultFactory->expects($this->once())
            ->method('createValidation')
            ->with('validateparadoxlabs_clover')
            ->willReturn($validation);

        $validation->expects($this->once())
            ->method('withFailureResult')
            ->with($errorMessage);

        $this->assertSame($validation, $this->component->evaluateCompletion($resultFactory));
    }

    /**
     * Build a stored-card mock. getTypeInstance() returns the same mock, matching Card behavior.
     */
    private function buildCard(
        string $hash,
        string $label,
        string $type,
        string $method = 'paradoxlabs_clover',
        int $customerId = 0,
    ): Card|MockObject {
        $card = $this->createMock(Card::class);
        $card->method('getTypeInstance')->willReturn($card);
        $card->method('getHash')->willReturn($hash);
        $card->method('getLabel')->willReturn($label);
        $card->method('getType')->willReturn($type);
        $card->method('getMethod')->willReturn($method);
        $card->method('getCustomerId')->willReturn($customerId);

        return $card;
    }
}
