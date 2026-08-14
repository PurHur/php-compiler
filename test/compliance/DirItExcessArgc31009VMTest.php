<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: DirectoryIterator/FilesystemIterator residual excess argc (#31009). */
final class DirItExcessArgc31009VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'spl_dirit_excess_argc_31009.phpt' => self::parsePHPT(
            __DIR__.'/cases/spl/spl_dirit_excess_argc_31009.phpt',
            'spl_dirit_excess_argc_31009.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
