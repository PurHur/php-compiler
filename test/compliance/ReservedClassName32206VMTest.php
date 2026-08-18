<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * VM: reserved class-like names are Zend compile fatals (#32206, zend_compile.c).
 *
 * Dedicated provider — path-slash data-set names break --filter on full VMTest.
 */
final class ReservedClassName32206VMTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'reserved_class_name_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reserved_class_name_compile_fatal.phpt',
            'reserved_class_name_compile_fatal.phpt'
        );
        yield 'reserved_class_name_mixed_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reserved_class_name_mixed_compile_fatal.phpt',
            'reserved_class_name_mixed_compile_fatal.phpt'
        );
        yield 'reserved_class_name_never_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reserved_class_name_never_compile_fatal.phpt',
            'reserved_class_name_never_compile_fatal.phpt'
        );
        yield 'reserved_interface_name_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reserved_interface_name_compile_fatal.phpt',
            'reserved_interface_name_compile_fatal.phpt'
        );
        yield 'reserved_trait_name_compile_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/reserved_trait_name_compile_fatal.phpt',
            'reserved_trait_name_compile_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }
}
