<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4697 — duplicate match default arm compile-time fatal */
final class MatchDuplicateDefaultCompileTest extends TestCase
{
    public function testDuplicateDefaultArmFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Match expressions may only contain one default arm');
        $runtime->parseAndCompile(<<<'PHP'
<?php
$x = match (1) {
    default => 1,
    default => 2,
};
PHP, 'match_duplicate_default.php');
    }

    public function testSingleDefaultArmStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
echo match (99) {
    1 => 'one',
    default => 'other',
};
PHP, 'match_single_default.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame('other', ob_get_clean());
    }
}
