<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: RecursiveDirectoryIterator getSubPath excess argc (#30936). */
final class RdiSubPathExcessArgc30936VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_rdi_subpath_30936.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/excess_argc_rdi_subpath_30936.phpt',
            'excess_argc_rdi_subpath_30936.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
