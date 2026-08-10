<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * JIT: readonly class typed static → Static property cannot be readonly (#29980).
 *
 * Dedicated provider — same pattern as ReadonlyClassTypedStaticFatal29980VMTest.
 */
require_once __DIR__.'/../BaseTest.php';

final class ReadonlyClassTypedStaticFatal29980JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'readonly_class_typed_static_property_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/readonly_class_typed_static_property_fatal.phpt',
            'readonly_class_typed_static_property_fatal.phpt'
        );
        yield 'readonly_class_static_property_fatal.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/readonly_class_static_property_fatal.phpt',
            'readonly_class_static_property_fatal.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
