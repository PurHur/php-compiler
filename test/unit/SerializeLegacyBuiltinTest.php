<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for __sleep / __wakeup and Serializable (issue #3287). */
final class SerializeLegacyBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/stdlib/serialize_legacy.phpt';
        yield 'serialize_legacy.phpt' => self::parsePHPT($path, 'serialize_legacy.phpt');
    }
}
