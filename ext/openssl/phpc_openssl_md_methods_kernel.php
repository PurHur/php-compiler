<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * @internal registry kernel for OpensslMethodsJitHelper (#21103).
 *
 * Avoids NestedJIT of {@see OpensslCipherRegistry} under user-script AOT (hash_algos #20652 peer).
 */
final class phpc_openssl_md_methods_kernel extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_openssl_md_methods_kernel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('phpc_openssl_md_methods_kernel() expects at most 1 argument');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(VmOpenssl::mdMethods(false));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('phpc_openssl_md_methods_kernel() expects at most 1 argument');
        }

        return JitOpensslMethodsKernel::invokeMdMethods($context);
    }
}
