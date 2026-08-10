<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for natsort(). */
final class NatsortVMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'natsort.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natsort.phpt',
            'natsort.phpt'
        );
        yield 'natsort_preserve_keys.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natsort_preserve_keys.phpt',
            'natsort_preserve_keys.phpt'
        );
        yield 'natsort_null_elements.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/natsort_null_elements.phpt',
            'natsort_null_elements.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
