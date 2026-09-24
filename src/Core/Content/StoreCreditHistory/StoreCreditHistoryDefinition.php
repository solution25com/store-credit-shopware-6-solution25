<?php

namespace Solu1StoreCredit\Core\Content\StoreCreditHistory;

use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\System\Currency\CurrencyDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\WriteProtected;
use Shopware\Core\Framework\Context;
use Solu1StoreCredit\Core\Content\StoreCredit\StoreCreditDefinition;

class StoreCreditHistoryDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'solu1_store_credit_history';
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
        $fields = new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('store_credit_id', 'storeCreditId', StoreCreditDefinition::class, 'id'))->addFlags(new Required()),
            new FkField('order_id', 'orderId', OrderDefinition::class, 'id'),
            new ReferenceVersionField(OrderDefinition::class),
            new FkField('currency_id', 'currencyId', CurrencyDefinition::class, 'id'),
            (new FloatField('amount', 'amount'))->addFlags(new Required()),
            (new StringField('reason', 'reason', 255)),
            (new StringField('action_type', 'actionType'))->addFlags(new Required()),
            new CreatedAtField(),
            new UpdatedAtField(),
            new StringField('operation_key', 'operationKey', 100),

            new ManyToOneAssociationField('currency', 'currency_id', CurrencyDefinition::class, 'id'),
        ]);
        foreach ($fields as $field) {
            if (in_array($field->getPropertyName(), ['customerId', 'currencyId', 'balance', 'storeCreditId', 'orderId', 'orderVersionId', 'amount', 'reason', 'actionType', 'operationKey'], true)) {
                $field->addFlags(new WriteProtected(Context::SYSTEM_SCOPE));
            }
        }
        return $fields;
    }
}
