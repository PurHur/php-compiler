<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: $this->prop assign in instance method / typed constructor (#32367). */
final class MethodPropAssign32367VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'method_prop_assign_32367.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/method_prop_assign_32367.phpt',
            'method_prop_assign_32367.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
