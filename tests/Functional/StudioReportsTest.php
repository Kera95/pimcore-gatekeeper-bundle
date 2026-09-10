<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Functional;

use Pimcore\Bundle\CustomReportsBundle\Tool\Config;
use Tsf\GatekeeperBundle\Service\ResultStore;
use Tsf\GatekeeperBundle\Tests\Support\FunctionalTestCase;

/**
 * The report definitions the extension prepends into the Custom Reports bundle
 */
final class StudioReportsTest extends FunctionalTestCase
{
    public function testTheTwoReportsAreRegisteredAndTheirSqlRuns(): void
    {
        $product = $this->product($this->completeValues());
        $product->save();

        $objects = Config::getByName('tsf_gatekeeper_objects');
        self::assertInstanceOf(Config::class, $objects);
        self::assertSame('sql', $objects->getDataSourceConfig()->type);
        $objectsSql = (string) $objects->getDataSourceConfig()->sql;
        self::assertStringContainsString(ResultStore::TABLE, $objectsSql);

        $summary = Config::getByName('tsf_gatekeeper_summary');
        self::assertInstanceOf(Config::class, $summary);
        $summarySql = (string) $summary->getDataSourceConfig()->sql;

        // the Custom Reports SQL adapter wraps the statement in a subquery and orders outside it,
        // exactly the way this does; neither statement orders on its own
        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM (' . $objectsSql . ') r ORDER BY r.profile, r.language');
        self::assertCount(3, $rows);
        self::assertSame($product->getKey(), $rows[0]['object_key']);
        self::assertSame(['default', 'default', 'print'], array_column($rows, 'profile'));

        $totals = $this->connection()->fetchAllAssociative('SELECT * FROM (' . $summarySql . ') r ORDER BY r.class_name, r.profile, r.language');
        self::assertCount(3, $totals);
        self::assertSame(['GkProduct', 'default', 'de'], [$totals[0]['class_name'], $totals[0]['profile'], $totals[0]['language']]);
    }
}
