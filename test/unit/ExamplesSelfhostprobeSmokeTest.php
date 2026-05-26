<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * 008-SelfHostProbe make smoke target (issue #2240).
 */
final class ExamplesSelfhostprobeSmokeTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testMakefileHasExamplesSelfhostprobeSmokeTarget(): void
    {
        $makefile = (string) file_get_contents(self::$root.'/Makefile');
        $this->assertStringContainsString('examples-selfhostprobe-smoke:', $makefile);
        $this->assertStringContainsString('examples-selfhostprobe-smoke.sh', $makefile);
    }

    public function testExamplesSelfhostprobeSmokeScriptExists(): void
    {
        $script = self::$root.'/script/examples-selfhostprobe-smoke.sh';
        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script));
        $body = (string) file_get_contents($script);
        $this->assertStringContainsString('008-SelfHostProbe/example.php', $body);
        $this->assertStringContainsString('north-star2-verify', $body);
    }

    public function testReadmeDocumentsMakeTarget(): void
    {
        $readme = (string) file_get_contents(self::$root.'/examples/008-SelfHostProbe/README.md');
        $this->assertStringContainsString('make examples-selfhostprobe-smoke', $readme);
    }
}
