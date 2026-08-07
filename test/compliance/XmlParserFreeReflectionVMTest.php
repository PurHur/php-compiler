<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for xml_parser_free Reflection stubs (#27793). */
final class XmlParserFreeReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'xml_parser_free_reflection_types.phpt';
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
