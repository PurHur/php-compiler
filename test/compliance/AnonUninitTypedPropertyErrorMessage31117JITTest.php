<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: uninit typed property Error on anonymous class is class@anonymous (#31117).
 *
 * Dedicated provider — full JITTest discovery is heavy, and path-slash data-set
 * names break --filter.
 */
final class AnonUninitTypedPropertyErrorMessage31117JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'anon_uninit_typed_property_error_message.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/anon_uninit_typed_property_error_message.phpt',
            'anon_uninit_typed_property_error_message.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
