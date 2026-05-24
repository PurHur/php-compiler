<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for __serialize / __unserialize (issue #1365). */
final class SerializeMagicBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/stdlib/serialize_magic.phpt';
        yield 'serialize_magic.phpt' => self::parsePHPT($path, 'serialize_magic.phpt');
    }
}
