<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\Model;

use Codeception\Test\Unit;
use Tsf\GatekeeperBundle\Model\ResultRow;

final class ResultRowTest extends Unit
{
    public function testHydratesFromAStoreRow(): void
    {
        $row = ResultRow::fromArray([
            'object_id' => '12',
            'object_key' => 'cable-xl',
            'path' => '/products/cable-xl',
            'class_name' => 'Product',
            'published' => '1',
            'type' => 'object',
            'profile' => 'print',
            'language' => 'de',
            'score' => '60',
            'threshold' => '100',
            'passed' => '0',
            'required_count' => '5',
            'missing_count' => '2',
            'missing_fields' => 'description,bricks.Dimensions.width',
            'calculated_at' => '2026-09-11 10:20:30',
        ]);

        self::assertSame(12, $row->getObjectId());
        self::assertSame('cable-xl', $row->getObjectKey());
        self::assertSame('/products/cable-xl', $row->getPath());
        self::assertSame('Product', $row->getClassName());
        self::assertTrue($row->isPublished());
        self::assertSame('print', $row->getProfile());
        self::assertSame('de', $row->getLanguage());
        self::assertSame('print/de', $row->getLabel());
        self::assertSame(60, $row->getScore());
        self::assertSame(100, $row->getThreshold());
        self::assertFalse($row->isPassed());
        self::assertSame(5, $row->getRequiredCount());
        self::assertSame(2, $row->getMissingCount());
        self::assertSame(['description', 'bricks.Dimensions.width'], $row->getMissing());
        self::assertSame('2026-09-11 10:20:30', $row->getCalculatedAt()->format('Y-m-d H:i:s'));
    }

    public function testAPassingRowHasNoMissingFields(): void
    {
        $row = ResultRow::fromArray([
            'object_id' => 3,
            'object_key' => 'k',
            'path' => '/k',
            'class_name' => 'Product',
            'published' => 0,
            'profile' => 'default',
            'language' => '',
            'score' => 100,
            'threshold' => 100,
            'passed' => 1,
            'required_count' => 2,
            'missing_count' => 0,
            'missing_fields' => '',
            'calculated_at' => '2026-09-11 10:20:30',
        ]);

        self::assertSame([], $row->getMissing());
        self::assertSame(0, $row->getMissingCount());
        self::assertSame('default', $row->getLabel());
        self::assertTrue($row->isPassed());
        self::assertFalse($row->isPublished());
    }
}
