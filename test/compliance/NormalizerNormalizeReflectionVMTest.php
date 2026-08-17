<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for normalizer_normalize() Reflection stubs (#25586). */
final class NormalizerNormalizeReflectionVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        $file = 'normalizer_normalize_reflection_25586.phpt';
        yield $file => self::parsePHPT(
            __DIR__.'/cases/intl/'.$file,
            $file
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
