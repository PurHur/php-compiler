<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM: class_exists Reflection $autoload default true (#25013). */
final class ClassExistsReflectionAutoloadDefault25013VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'class_exists_reflection_autoload_default.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/class_exists_reflection_autoload_default.phpt',
            'class_exists_reflection_autoload_default.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
