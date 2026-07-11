<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for serialize() visibility mangling (issue #15751). */
final class SerializeVisibilityBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/../compliance/cases/stdlib/serialize_visibility.phpt';
        yield 'serialize_visibility.phpt' => self::parsePHPT($path, 'serialize_visibility.phpt');
    }
}
