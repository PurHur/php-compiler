<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime::diff invert y/m/d borrows from earlier date (#32075).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeDiffInvertMd32075JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_diff_invert_md.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/datetime_diff_invert_md.phpt',
            'datetime_diff_invert_md.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
