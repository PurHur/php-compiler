<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * @group llvm
 */
/** JIT compliance for htmlspecialchars() combined ENT_* flags (#11027). */
final class HtmlspecialcharsCombinedFlagsJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'htmlspecialchars_combined_flags_jit.phpt' => self::parsePHPT(
            __DIR__.'/cases/stdlib/htmlspecialchars_combined_flags_jit.phpt',
            'htmlspecialchars_combined_flags_jit.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
    }
}
