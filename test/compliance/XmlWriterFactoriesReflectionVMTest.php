<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for XMLWriter::toMemory/toUri/toStream Reflection (#27922). */
final class XmlWriterFactoriesReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'to_factories_reflection_84.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/xmlwriter/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
