<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5359 — match default arm may precede other arms (Zend 8.2+) */
final class MatchDefaultNotLastCompileTest extends TestCase
{
    public function testDefaultNotLastCompilesAndEvaluatesInSourceOrder(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
echo match (1) {
    default => 'd',
    1 => 'a',
}, "\n";
echo match (0) {
    default => 'd',
    0 => 'z',
}, "\n";
PHP, 'match_default_not_last.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("a\nz\n", ob_get_clean());
    }

    public function testDefaultLastStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
echo match (1) {
    2 => 'two',
    default => 'other',
};
PHP, 'match_default_last.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('other', ob_get_clean());
    }

    public function testDefaultInMiddleDefersUntilLaterArmsChecked(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
echo match (5) {
    1 => 'a',
    default => 'd',
    2 => 'b',
};
PHP, 'match_default_middle.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('d', ob_get_clean());
    }
}
