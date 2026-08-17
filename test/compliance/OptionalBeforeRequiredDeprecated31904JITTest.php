<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT: optional-before-required E_DEPRECATED (#31904).
 *
 * Dedicated provider — path-slash data-set names break --filter on full JITTest.
 */
final class OptionalBeforeRequiredDeprecated31904JITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'optional_before_required_deprecated.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/optional_before_required_deprecated.phpt',
            'optional_before_required_deprecated.phpt'
        );
        yield 'optional_before_required_deprecated_file.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/optional_before_required_deprecated_file.phpt',
            'optional_before_required_deprecated_file.phpt'
        );
        yield 'named_arg_optional_before_required.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/named_arg_optional_before_required.phpt',
            'named_arg_optional_before_required.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
