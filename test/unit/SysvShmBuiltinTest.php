<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for ext/sysvshm shm_* and shmop_* APIs (#6436, #3344). */
final class SysvShmBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        if (!\function_exists('shm_attach')) {
            return;
        }

        $path = __DIR__.'/../compliance/cases/stdlib/shm_attach_roundtrip.phpt';
        yield 'shm_attach_roundtrip.phpt' => self::parsePHPT($path, 'shm_attach_roundtrip.phpt');

        $reflectionPath = __DIR__.'/../compliance/cases/stdlib/shm_reflection_names_24640.phpt';
        yield 'shm_reflection_names_24640.phpt' => self::parsePHPT($reflectionPath, 'shm_reflection_names_24640.phpt');

        $typesPath = __DIR__.'/../compliance/cases/stdlib/shm_attach_reflection_types_27943.phpt';
        yield 'shm_attach_reflection_types_27943.phpt' => self::parsePHPT($typesPath, 'shm_attach_reflection_types_27943.phpt');

        if (!\function_exists('shmop_open')) {
            return;
        }

        $shmopPath = __DIR__.'/../compliance/cases/stdlib/shmop_roundtrip.phpt';
        yield 'shmop_roundtrip.phpt' => self::parsePHPT($shmopPath, 'shmop_roundtrip.phpt');

        $shmopTypesPath = __DIR__.'/../compliance/cases/stdlib/shmop_reflection_types_28451.phpt';
        yield 'shmop_reflection_types_28451.phpt' => self::parsePHPT($shmopTypesPath, 'shmop_reflection_types_28451.phpt');

        $loadedPath = __DIR__.'/../compliance/cases/stdlib/extension_loaded_shmop.phpt';
        yield 'extension_loaded_shmop.phpt' => self::parsePHPT($loadedPath, 'extension_loaded_shmop.phpt');
    }
}
