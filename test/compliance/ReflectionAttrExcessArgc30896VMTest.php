<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: ReflectionAttribute/NamedType/ClassConstant/Property excess argc (#30896). */
final class ReflectionAttrExcessArgc30896VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'excess_argc_reflection_attr_30896.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/excess_argc_reflection_attr_30896.phpt',
            'excess_argc_reflection_attr_30896.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
