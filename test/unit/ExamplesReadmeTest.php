<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Shipped examples documented in examples/README.md (issues #160, #262).
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

    public function testRunMatrixDocumentsPhpcFirst(): void
    {
        $readme = file_get_contents(dirname(__DIR__, 2).'/examples/README.md');
        $this->assertNotFalse($readme);
        $this->assertStringContainsString('## Run matrix', $readme);
        $this->assertStringContainsString('| Example | VM | JIT | AOT build |', $readme);
        $this->assertStringContainsString('./phpc lint', $readme);
        $this->assertStringContainsString('./phpc run', $readme);
        $this->assertStringContainsString('ExamplesCompileTest.php', $readme);
        $this->assertStringContainsString('make test-docker', $readme);
    }
}
