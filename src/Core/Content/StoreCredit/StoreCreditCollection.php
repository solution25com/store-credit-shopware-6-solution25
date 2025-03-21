<?php

namespace StoreCredit\Core\Content\StoreCredit;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class StoreCreditCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return StoreCreditEntity::class;
    }
}
