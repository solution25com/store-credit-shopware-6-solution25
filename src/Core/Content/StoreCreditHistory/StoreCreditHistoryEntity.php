<?php

namespace Solu1StoreCredit\Core\Content\StoreCreditHistory;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;
use Shopware\Core\System\Currency\CurrencyEntity;

class StoreCreditHistoryEntity extends Entity
{
    use EntityIdTrait;

    /**
     * @var string
     */
    protected $id;

    /**
     * @var string
     */
    protected $storeCreditId;

    /**
     * @var string
     */
    protected ?string $orderId = null;

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

    /**
     * @var \DateTimeInterface|null
     */
    protected $createdAt;

    /**
     * @var \DateTimeInterface|null
     */
    protected $updatedAt;


    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

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
