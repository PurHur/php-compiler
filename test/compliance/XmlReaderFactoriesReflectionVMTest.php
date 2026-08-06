<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for XMLReader::fromString/fromUri/fromStream Reflection (#27713). */
final class XmlReaderFactoriesReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'from_factories_reflection_84.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/xmlreader/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
