<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Functional;

use Tsf\GatekeeperBundle\Model\ResultRow;
use Tsf\GatekeeperBundle\Service\ResultStore;
use Tsf\GatekeeperBundle\Tests\Support\FunctionalTestCase;

/**
 * The typed read API other bundles consume: findFailing() and findByObject()
 */
final class ResultStoreReadApiTest extends FunctionalTestCase
{
    public function testFindFailingReturnsTypedRowsNarrowedByClassProfileAndLanguage(): void
    {
        $complete = $this->product($this->completeValues());
        $complete->save();
        $incomplete = $this->product(['sku' => 'SKU-7', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false);
        $incomplete->save();
        $category = $this->category('Audio');
        $category->save();

        /** @var ResultStore $store */
        $store = $this->service(ResultStore::class);

        $all = $store->findFailing();
        self::assertContainsOnlyInstancesOf(ResultRow::class, $all);
        self::assertCount(3, $all, 'default/de and print/en of the product, default/en of the category');

        $product = $store->findFailing('GkProduct');
        self::assertCount(2, $product);
        self::assertSame([$incomplete->getId(), $incomplete->getId()], array_map(static fn (ResultRow $r): int => $r->getObjectId(), $product));

        $de = $store->findFailing('GkProduct', 'default', 'de');
        self::assertCount(1, $de);
        self::assertSame('default/de', $de[0]->getLabel());
        self::assertSame(['title'], $de[0]->getMissing());
        self::assertSame(67, $de[0]->getScore());
        self::assertFalse($de[0]->isPassed());
        self::assertFalse($de[0]->isPublished());
        self::assertSame($incomplete->getKey(), $de[0]->getObjectKey());
        self::assertSame($incomplete->getRealFullPath(), $de[0]->getPath());

        $print = $store->findFailing('GkProduct', 'print');
        self::assertCount(1, $print);
        self::assertSame(['description', 'bricks.GkDimensions.width', 'features.GkFeature.label'], $print[0]->getMissing());

        self::assertCount(1, $store->findFailing(null, null, null, 1));
        self::assertSame([], $store->findFailing('GkProduct', 'default', 'en'));
    }

    public function testFindByObjectReturnsEveryRowOfTheObject(): void
    {
        $product = $this->product(['sku' => 'SKU-8', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false);
        $product->save();

        /** @var ResultStore $store */
        $store = $this->service(ResultStore::class);

        $rows = $store->findByObject($product->getId());
        self::assertSame(['default/de', 'default/en', 'print/en'], array_map(static fn (ResultRow $r): string => $r->getLabel(), $rows));
        self::assertSame([67, 100, 0], array_map(static fn (ResultRow $r): int => $r->getScore(), $rows));

        self::assertSame([], $store->findByObject(999999));
    }
}
