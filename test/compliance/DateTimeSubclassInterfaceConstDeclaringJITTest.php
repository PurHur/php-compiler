<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: DateTime subclass inherits DateTimeInterface format constants (#30229).
 *
 * @group llvm
 * @group jit
 */
final class DateTimeSubclassInterfaceConstDeclaringJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'datetime_subclass_interface_const_declaring.phpt' => self::parsePHPT(
            __DIR__.'/cases/date/datetime_subclass_interface_const_declaring.phpt',
            'datetime_subclass_interface_const_declaring.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
