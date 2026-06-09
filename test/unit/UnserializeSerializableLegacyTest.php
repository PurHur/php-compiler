<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for legacy Serializable::unserialize() (issue #4772). */
final class UnserializeSerializableLegacyTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/stdlib/unserialize_serializable_legacy.phpt';
        yield 'unserialize_serializable_legacy.phpt' => self::parsePHPT($path, 'unserialize_serializable_legacy.phpt');
    }
}
