<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * debug_zval_dump() — internal zval introspection (Zend/zend_builtin_functions.c, #6576).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_builtin_functions.c zif_debug_zval_dump
 * JIT/AOT: scalar lowering via JitDebugZvalDump → JitVarDump (#6084).
 */
final class debug_zval_dump extends Internal
{
    public function __construct()
    {
        parent::__construct('debug_zval_dump');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('debug_zval_dump() requires VM context');
        }
        $vm = $frame->vmContext->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('debug_zval_dump() requires an active VM');
        }
        foreach ($frame->calledArgs as $arg) {
            VmDebugZval::dumpVariable($vm, $arg->resolveIndirect(), 0, false, $frame);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDebugZvalDump::invoke($context, ...$args);
    }
}
