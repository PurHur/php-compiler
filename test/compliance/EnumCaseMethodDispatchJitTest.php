<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compliance for enum case instance method dispatch (#9658, Zend/zend_enum.c).
 *
 * @group llvm
 * @group jit
 */
final class EnumCaseMethodDispatchJitTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        yield 'enum_case_method_dispatch.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/enum_case_method_dispatch.phpt',
            'enum_case_method_dispatch.phpt'
        );
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }
}
