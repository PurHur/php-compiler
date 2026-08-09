<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/ClassMemberConstVMTest.php';

/**
 * JIT: constant()/defined() resolve self::/static::/parent:: (#29455).
 *
 * @group llvm
 * @group jit
 */
class ConstantDefinedRelativeClassScopeJITTest extends ClassMemberConstVMTest
{
    public static function providePHPTests(): \Generator
    {
        yield 'constant_defined_relative_class_scope.phpt' => self::parsePHPT(
            __DIR__.'/cases/language/constant_defined_relative_class_scope.phpt',
            'constant_defined_relative_class_scope.phpt'
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
