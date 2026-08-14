<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: class_alias() excess argc → ArgumentCountError (#30783). */
final class ClassAliasExcessArgc30783VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_class_alias_30783.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_class_alias_30783.phpt',
            'excess_argc_class_alias_30783.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
