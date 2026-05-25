<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Shipped examples documented in examples/README.md (issues #160, #262, #753).
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

    public function testMiniWebAppAotStatusReflectsLinkAndExecuteGreen(): void
    {
        $root = dirname(__DIR__, 2);
        $readme = (string) file_get_contents($root.'/examples/README.md');
        $mini = (string) file_get_contents($root.'/examples/003-MiniWebApp/README.md');

        foreach ([$readme, $mini] as $body) {
            $this->assertStringNotContainsString('blocked #568', $body);
            $this->assertStringNotContainsString('❌ blocked', $body);
            $this->assertStringNotContainsString('empty stdout until', $body);
            $this->assertStringNotContainsString('native execute 📋', $body);
        }

        $this->assertStringContainsString('native execute ✅', $readme);
        $this->assertStringContainsString('#764', $readme);
        $this->assertStringContainsString('phpc build --project', $readme);
        $this->assertStringContainsString('AOT link', $mini);
        $this->assertStringContainsString('native execute ✅', $mini);
        $this->assertStringContainsString('#764', $mini);
    }
}
