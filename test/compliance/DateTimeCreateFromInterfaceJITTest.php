<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime(Immutable) createFromInterface/Immutable/Mutable (#30762).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeCreateFromInterfaceJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_create_from_interface_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_create_from_interface_jit.phpt',
            'datetime_create_from_interface_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
