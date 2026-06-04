<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance: PHP 8.3 dynamic class constant fetch (#5923). */
final class DynamicClassConstantVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'dynamic_class_constant.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/dynamic_class_constant.phpt',
            'dynamic_class_constant.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
