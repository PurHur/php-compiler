<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM/JIT compliance for ini_set() (issue #1374). */
final class IniSetComplianceTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach (['ini_set.phpt', 'ini_set_jit.phpt'] as $file) {
            $path = __DIR__.'/../compliance/cases/stdlib/'.$file;
            yield $file => self::parsePHPT($path, $file);
        }
    }
}
