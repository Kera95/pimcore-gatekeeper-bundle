<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\Model;

use Codeception\Test\Unit;
use Tsf\GatekeeperBundle\Model\Evaluation;
use Tsf\GatekeeperBundle\Model\Result;

final class ResultTest extends Unit
{
    public function testScoreIsRoundedPercentageOfFilledFields(): void
    {
        $result = new Result('default', '', ['a', 'b', 'c'], ['c'], 100);

        self::assertSame(67, $result->getScore());
        self::assertFalse($result->isPassed());
        self::assertSame(3, $result->getRequiredCount());
        self::assertSame(1, $result->getMissingCount());
    }

    public function testNothingRequiredIsComplete(): void
    {
        self::assertSame(100, (new Result('default', '', [], [], 100))->getScore());
    }

    public function testThresholdDecidesPassed(): void
    {
        self::assertTrue((new Result('print', 'de', ['a', 'b', 'c', 'd', 'e'], ['e'], 80))->isPassed());
        self::assertFalse((new Result('print', 'de', ['a', 'b', 'c', 'd', 'e'], ['e', 'd'], 80))->isPassed());
    }

    public function testLabelIncludesTheLanguageOnlyWhenPresent(): void
    {
        self::assertSame('default', (new Result('default', '', ['a'], [], 100))->getLabel());
        self::assertSame('print/de', (new Result('print', 'de', ['a'], [], 100))->getLabel());
    }

    public function testEvaluationAggregatesTheWorstScore(): void
    {
        $evaluation = new Evaluation('Product', [
            new Result('default', 'en', ['a', 'b'], [], 100),
            new Result('default', 'de', ['a', 'b'], ['b'], 100),
            new Result('print', '', ['a', 'b', 'c', 'd'], ['d'], 70),
        ]);

        self::assertSame(50, $evaluation->getAggregateScore());
        self::assertFalse($evaluation->isPassed());
        self::assertCount(1, $evaluation->getFailed());
        self::assertSame('default/de (50% < 100%): b', $evaluation->describeFailures());
    }

    public function testEvaluationWithoutResultsIsComplete(): void
    {
        $evaluation = new Evaluation('Product', []);

        self::assertSame(100, $evaluation->getAggregateScore());
        self::assertTrue($evaluation->isPassed());
        self::assertSame('', $evaluation->describeFailures());
    }
}
