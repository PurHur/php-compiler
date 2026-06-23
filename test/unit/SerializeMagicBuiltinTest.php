<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for __serialize / __unserialize (issues #1365, #3368). */
final class SerializeMagicBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        foreach (
            [
                'serialize_magic.phpt' => __DIR__.'/../compliance/cases/stdlib/serialize_magic.phpt',
                'serialize_magic_methods.phpt' => __DIR__.'/../compliance/cases/language/serialize_magic_methods.phpt',
                'serialize_bad_return_type.phpt' => __DIR__.'/../compliance/cases/stdlib/serialize_bad_return_type.phpt',
            ] as $name => $path
        ) {
            yield $name => self::parsePHPT($path, $name);
        }
    }
}
