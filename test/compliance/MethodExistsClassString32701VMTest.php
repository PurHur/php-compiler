<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: method_exists() class-string literal and runtime (#32701). */
final class MethodExistsClassString32701VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'method_exists_class_string_32701.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/method_exists_class_string_32701.phpt',
            'method_exists_class_string_32701.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
