<?php

declare(strict_types=1);

use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Guard Zend full-spine parseAndCompile against premature block-comment closes (#22642).
 *
 * A token sequence like DateTime*\/ inside a docblock ends the comment early and leaves
 * the next `*` line as syntax — r9 died after ~226m on SplArrayCastJitHelper.php.
 */
final class SpineDocblockParseGuardTest extends TestCase
{
    public function testSplArrayCastJitHelperParsesWithNikic(): void
    {
        $path = dirname(__DIR__, 2).'/lib/VM/SplArrayCastJitHelper.php';
        $this->assertFileExists($path);
        $source = (string) file_get_contents($path);
        $this->assertStringNotContainsString('DateTime*/', $source);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($source);
        $this->assertNotNull($ast);
        $this->assertNotEmpty($ast);
    }

    /** Spot-check VM *JitHelper.php sources that the spine requires. */
    public function testVmJitHelpersHaveNoPrematureDocblockClose(): void
    {
        $root = dirname(__DIR__, 2).'/lib/VM';
        $files = glob($root.'/*JitHelper.php') ?: [];
        $this->assertNotEmpty($files);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $failures = [];
        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            try {
                $parser->parse($source);
            } catch (Throwable $e) {
                $failures[] = basename($path).': '.$e->getMessage();
            }
        }
        $this->assertSame([], $failures);
    }
}
