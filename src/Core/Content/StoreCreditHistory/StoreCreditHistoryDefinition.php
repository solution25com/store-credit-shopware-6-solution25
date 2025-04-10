<?php

namespace StoreCredit\Core\Content\StoreCreditHistory;

use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\System\Currency\CurrencyDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use StoreCredit\Core\Content\StoreCredit\StoreCreditDefinition;

class StoreCreditHistoryDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'store_credit_history';
    }

    public function getEntityClass(): string
    {
        return StoreCreditHistoryEntity::class;
    }

    public function getCollectionClass(): string
    {
        return StoreCreditHistoryCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('store_credit_id', 'storeCreditId', StoreCreditDefinition::class, 'id'))->addFlags(new Required()),
            new FkField('order_id', 'orderId', OrderDefinition::class, 'id'),
            new FkField('currency_id', 'currencyId', CurrencyDefinition::class, 'id'),
            (new FloatField('amount', 'amount'))->addFlags(new Required()),
            (new StringField('reason', 'reason', 255)),
            (new StringField('action_type', 'actionType'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
            (new DateTimeField('updated_at', 'updatedAt'))->addFlags(new Required()),
        ]);
    }
}
