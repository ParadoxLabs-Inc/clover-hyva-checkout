<?php

declare(strict_types=1);

/**
 * Paradox Labs, Inc.
 * http://www.paradoxlabs.com
 * 717-431-3330
 *
 * Need help? Open a ticket in our support system:
 *  http://support.paradoxlabs.com
 *
 * @author      Ryan Hoerr <info@paradoxlabs.com>
 * @license     http://store.paradoxlabs.com/license.html
 */

namespace ParadoxLabs\CloverHyvaCheckout\Magewire\Payment;

use Hyva\Checkout\Model\Magewire\Component\EvaluationInterface;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultFactory;
use Hyva\Checkout\Model\Magewire\Component\EvaluationResultInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magewirephp\Magewire\Component\Form;
use ParadoxLabs\CloverHyvaCheckout\ViewModel\PaymentForm;
use ParadoxLabs\TokenBase\Api\CardRepositoryInterface;
use ParadoxLabs\TokenBase\Api\Data\CardInterface;
use ParadoxLabs\TokenBase\Block\Form\Cc;
use ParadoxLabs\TokenBase\Model\Card;
use Rakit\Validation\Validator;

/**
 * Magewire component for the Clover hosted-iframe payment form.
 *
 * Card entry happens client-side in the Clover SDK hosted-field iframes (see scripts.phtml); this
 * component owns the stored-card list and the place-order evaluation. Clover tokens are
 * amount-agnostic — the charge amount is set server-side at sale — so no quote-total sync with the
 * browser is needed.
 */
class Clover extends Form implements EvaluationInterface
{
    protected const METHOD_CODE = 'paradoxlabs_clover';

    /* Public component properties */
    public $storedCards = [];

    /* Protected property validation rule map */
    protected $rules
        = [
            'storedCards' => 'array',
        ];

    /**
     * @param \Rakit\Validation\Validator $validator
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \ParadoxLabs\TokenBase\Api\CardRepositoryInterface $cardRepository
     * @param \ParadoxLabs\CloverHyvaCheckout\ViewModel\PaymentForm $formViewModel
     */
    public function __construct(
        Validator $validator,
        protected CheckoutSession $checkoutSession,
        protected CardRepositoryInterface $cardRepository,
        protected PaymentForm $formViewModel,
    ) {
        parent::__construct($validator);
    }

    /**
     * Initialize component data on every request.
     *
     * booted() (not boot()) — it runs after property hydration, so the mutation to
     * $this->storedCards is not clobbered by hydrated state on subsequent requests.
     */
    public function booted(): void
    {
        $this->loadSelectedCard();
    }

    /**
     * @throws LocalizedException
     */
    public function mount(): void
    {
        $this->loadStoredCards();
    }

    /**
     * Update component selected card based on the quote's assigned stored card
     *
     * @throws LocalizedException
     */
    protected function loadSelectedCard(): void
    {
        $payment = $this->getQuote()->getPayment();

        if ($payment->getData('tokenbase_id') !== null) {
            $card = $this->cardRepository->getById($payment->getData('tokenbase_id'));

            if ($card->getMethod() === static::METHOD_CODE
                && (int)$card->getCustomerId() === (int)$this->getQuote()->getCustomerId()) {
                $this->addStoredCardToList($card);
            }
        }
    }

    /**
     * Get the current user's active quote
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    protected function getQuote(): CartInterface
    {
        return $this->checkoutSession->getQuote();
    }

    /**
     * Determine whether checkout completion is allowed
     */
    public function evaluateCompletion(EvaluationResultFactory $resultFactory): EvaluationResultInterface
    {
        $validationError = $resultFactory->createErrorMessage();
        $validationError->withMessage('There\'s an issue with your payment details. Please check the payment form.');
        $validationError->withVisibilityDuration(5000);

        $validation = $resultFactory->createValidation('validate' . static::METHOD_CODE);
        $validation->withFailureResult($validationError);

        return $validation;
    }

    /**
     * Get the active payment method instance
     *
     * @throws LocalizedException
     */
    protected function getMethod(): MethodInterface
    {
        return $this->formViewModel->getMethod(static::METHOD_CODE);
    }

    /**
     * Get the active payment method form block
     *
     * @throws LocalizedException
     */
    protected function getFormBlock(): Cc
    {
        return $this->formViewModel->getFormBlock(static::METHOD_CODE);
    }

    /**
     * @throws LocalizedException
     */
    protected function loadStoredCards(): void
    {
        /** @var Card $card */
        foreach ($this->getFormBlock()->getStoredCards() as $card) {
            $this->addStoredCardToList($card);
        }
    }

    /**
     * Add the given card to the component stored-card list
     */
    protected function addStoredCardToList(CardInterface $card): void
    {
        $card = $card->getTypeInstance();
        $hash = (string)$card->getHash();

        $this->storedCards[$hash] = [
            'hash' => $hash,
            'label' => $card->getLabel(),
            'type' => $card->getType(),
        ];
    }
}
