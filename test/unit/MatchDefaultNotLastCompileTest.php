<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5061 — default match arm must be last */
final class MatchDefaultNotLastCompileTest extends TestCase
{
    public function testDefaultNotLastFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Default arm must be the last arm in the match expression');
        $runtime->parseAndCompile(<<<'PHP'
<?php
echo match (1) {
    default => 'd',
    1 => 'a',
};
PHP, 'match_default_not_last.php');
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
}
