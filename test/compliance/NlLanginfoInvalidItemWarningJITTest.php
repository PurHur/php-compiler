<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for nl_langinfo() invalid-item warning (#29459). */
final class NlLanginfoInvalidItemWarningJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'nl_langinfo_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/nl_langinfo_jit.phpt',
            'nl_langinfo_jit.phpt'
        );
        yield 'nl_langinfo_invalid_item_warning_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/nl_langinfo_invalid_item_warning_jit.phpt',
            'nl_langinfo_invalid_item_warning_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
