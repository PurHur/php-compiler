<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for Throwable ctor Z_PARAM_LONG $code coercion (#28797).
 *
 * @group llvm
 * @group jit
 */
final class ExceptionCtorJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'exception_ctor_code_coerce.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/exception_ctor_code_coerce.phpt',
            'exception_ctor_code_coerce.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM 9 not available');
        }
    }
}
