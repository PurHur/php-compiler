<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for get/set_include_path Reflection (#27796). */
final class IncludePathReflection27796VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'include_path_reflection_27796.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/stdlib/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
