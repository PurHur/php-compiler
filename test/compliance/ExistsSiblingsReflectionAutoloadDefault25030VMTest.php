<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: interface_exists/trait_exists/enum_exists Reflection $autoload default true (#25030). */
final class ExistsSiblingsReflectionAutoloadDefault25030VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'exists_siblings_reflection_autoload_default.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/exists_siblings_reflection_autoload_default.phpt',
            'exists_siblings_reflection_autoload_default.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
