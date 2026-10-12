<?php

namespace Solu1StoreCredit\Core\Content\StoreCredit;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<StoreCreditEntity>
 */
class StoreCreditCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return StoreCreditEntity::class;
    }
}
