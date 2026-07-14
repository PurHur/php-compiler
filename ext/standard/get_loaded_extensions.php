<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** get_loaded_extensions() — registered extension list (ext/standard/info.c parity, #3204). */
final class get_loaded_extensions extends Internal
{
    public function __construct()
    {
        parent::__construct('get_loaded_extensions');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('get_loaded_extensions() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $zendExtensions = false;
        if (1 === $argc) {
            $zendExtensions = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[0],
                'get_loaded_extensions',
                1,
                'zend_extensions'
            );
        }
        $frame->returnVar->array(VmInfo::get_loaded_extensions($zendExtensions));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('get_loaded_extensions() accepts at most one argument');
        }
        $zendExtensions = $context->constantFromBool(false);
        if (isset($args[0])) {
            $zendExtensions = JitBoolArg::lowerCoerce(
                $context,
                $args[0],
                'get_loaded_extensions(): Argument #1 ($zend_extensions)'
            );
        }

        return JitInfo::get_loaded_extensions($context, $zendExtensions);
    }
}
