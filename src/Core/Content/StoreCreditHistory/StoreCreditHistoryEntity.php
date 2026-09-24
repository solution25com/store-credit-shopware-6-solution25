<?php

namespace Solu1StoreCredit\Core\Content\StoreCreditHistory;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\Currency\CurrencyEntity;

class StoreCreditHistoryEntity extends Entity
{
    use EntityIdTrait;

    protected ?string $operationKey = null;

    public function getOperationKey(): ?string
    {
        return $this->operationKey;
    }

    public function setOperationKey(?string $operationKey): void
    {
        $this->operationKey = $operationKey;
    }


    /**
     * @var string
     */
    protected $storeCreditId;

    /**
     * @var string
     */
    protected ?string $orderId = null;
    protected ?string $orderVersionId = null;

    public function getOrderVersionId(): ?string { return $this->orderVersionId; }
    public function setOrderVersionId(?string $versionId): void { $this->orderVersionId = $versionId; }

    /**
     * @var float
     */
    protected $amount;

    /**
     * @var string|null
     */
    protected ?string $currencyId = null;

    /**
     * @var CurrencyEntity|null
     */
    protected ?CurrencyEntity $currency = null;

    /**
     * @var string
     */
    protected $reason;

    /**
     * @var string
     */
    protected $actionType;





    public function getStoreCreditId(): string
    {
        return $this->storeCreditId;
    }

    public function setStoreCreditId(string $storeCreditId): void
    {
        $this->storeCreditId = $storeCreditId;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function setOrderId(?string $orderId): void
    {
        $this->orderId = $orderId;
    }
    public function getCurrencyId(): ?string
    {
        return $this->currencyId;
    }

    public function setCurrencyId(?string $currencyId): void
    {
        $this->currencyId = $currencyId;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): void
    {
        $this->amount = $amount;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function setReason(string $reason): void
    {
        $this->reason = $reason;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function setActionType(string $actionType): void
    {
        $this->actionType = $actionType;
    }

    public function getCurrency(): ?CurrencyEntity
    {
        return $this->currency;
    }

    public function setCurrency(?CurrencyEntity $currency): void
    {
        $this->currency = $currency;
    }
}
