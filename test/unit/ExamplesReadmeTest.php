<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Shipped web examples stay listed in the benchmark table (issue #160).
 */
final class ExamplesReadmeTest extends TestCase
{
    public function testBenchmarkTableListsAllExamples(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2).'/examples/README.md');
        $this->assertNotFalse($readme);
        foreach (['000-HelloWorld', '001-SimpleWeb', '002-StaticWeb'] as $example) {
            $this->assertStringContainsString($example, $readme);
        }
    }
}
