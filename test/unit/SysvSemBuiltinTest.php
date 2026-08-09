<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for ext/sysvsem sem_* APIs (#3704). */
final class SysvSemBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        if (!\function_exists('sem_get')) {
            return;
        }

        $path = __DIR__.'/../compliance/cases/stdlib/sem_acquire_release.phpt';
        yield 'sem_acquire_release.phpt' => self::parsePHPT($path, 'sem_acquire_release.phpt');

        $path = __DIR__.'/../compliance/cases/sysvsem/sem_get_auto_release_bool.phpt';
        yield 'sem_get_auto_release_bool.phpt' => self::parsePHPT($path, 'sem_get_auto_release_bool.phpt');

        $path = __DIR__.'/../compliance/cases/sysvsem/sem_acquire_reflection_names_24610.phpt';
        yield 'sem_acquire_reflection_names_24610.phpt' => self::parsePHPT($path, 'sem_acquire_reflection_names_24610.phpt');

        $typesPath = __DIR__.'/../compliance/cases/sysvsem/sem_reflection_types_28453.phpt';
        yield 'sem_reflection_types_28453.phpt' => self::parsePHPT($typesPath, 'sem_reflection_types_28453.phpt');
    }
}
