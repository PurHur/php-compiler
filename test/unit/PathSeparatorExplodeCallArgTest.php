<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @coversNothing */
final class PathSeparatorExplodeCallArgTest extends TestCase
{
    /** Issue #15833 — comparison prelude must not bind explode separator arg. */
    public function testExplodePathSeparatorAfterDefinedAndCompareGuards(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/maintainer_gap_directory_path_separator_constants.php');
        self::assertNotFalse($code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_directory_path_separator_constants.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("separators_ok\n", ob_get_clean());
    }
}
