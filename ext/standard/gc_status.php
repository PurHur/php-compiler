<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * gc_status() — cyclic GC statistics (ext/standard/php_gc.c parity, #3280).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_builtin_functions.c ZEND_FUNCTION(gc_status)
 */
final class gc_status extends Internal
{
    public function __construct()
    {
        parent::__construct('gc_status');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 0) {
            throw new \ArgumentCountError('gc_status() expects exactly 0 arguments, '.\count($frame->calledArgs).' given');
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('gc_status() requires VM context');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->array(VmGcStatus::statusTable($ctx));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitGcStatus::invokeWithArgs($context, \count($args));
    }
}
