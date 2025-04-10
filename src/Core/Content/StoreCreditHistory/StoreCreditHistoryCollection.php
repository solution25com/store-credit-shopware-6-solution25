<?php

namespace StoreCredit\Core\Content\StoreCreditHistory;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

class StoreCreditHistoryCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return StoreCreditHistoryEntity::class;
    }
}
