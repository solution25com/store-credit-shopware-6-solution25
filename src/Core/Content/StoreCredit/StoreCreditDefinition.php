<?php

namespace StoreCredit\Core\Content\StoreCredit;

use Shopware\Core\Checkout\Customer\CustomerDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToOneAssociationField;
use Shopware\Core\System\Currency\CurrencyDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class StoreCreditDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'store_credit';
    }

    public function getEntityClass(): string
    {
        return StoreCreditEntity::class;
    }

    public function getCollectionClass(): string
    {
        return StoreCreditCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new FkField('customer_id', 'customerId', CustomerDefinition::class, 'id'))->addFlags(new Required()),
            new FkField('currency_id', 'currencyId', CurrencyDefinition::class, 'id'),
            (new FloatField('balance', 'balance'))->addFlags(new Required()),
            (new DateTimeField('updated_at', 'updatedAt')),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),

            new OneToOneAssociationField('customer', 'customer_id', 'id', CustomerDefinition::class),
        ]);
    }
}
