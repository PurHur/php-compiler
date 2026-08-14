<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: SplFileObject residual excess argc (#31008). */
final class SplFileObjectExcessArgc31008VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_fileobject_excess_argc_31008.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_fileobject_excess_argc_31008.phpt',
            'spl_fileobject_excess_argc_31008.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
