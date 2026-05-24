<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * README north-star links point at open trackers (issue #1779).
 */
final class ReadmeNorthStarTest extends TestCase
{
    public function testReadmeSelfHostNorthStarLinksOpenTracker(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
        $this->assertStringContainsString(
            '[Self-host #1492](https://github.com/PurHur/php-compiler/issues/1492)',
            $readme
        );
        $this->assertStringContainsString(
            '**Living tracker:** [#1492](https://github.com/PurHur/php-compiler/issues/1492)',
            $readme
        );
        $this->assertStringNotContainsString(
            '[Self-host #1056](https://github.com/PurHur/php-compiler/issues/1056)',
            $readme
        );
    }
}
