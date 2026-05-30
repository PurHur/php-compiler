<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg->type) {
                throw new \LogicException(
                    'get_loaded_extensions() zend_extensions must be boolean in this compiler build'
                );
            }
            $zendExtensions = $arg->toBool();
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
            $zendExtensions = JitBoolArg::lower($context, $args[0], 'get_loaded_extensions() zend_extensions');
        }

        return JitInfo::get_loaded_extensions($context, $zendExtensions);
    }
}
