<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * dl() — runtime extension load stub (ext/standard/dl.c parity, issue #3779).
 *
 * v1: enable_dl is always off; emit Zend warning and return false (no .so loading).
 */
final class dl extends Internal
{
    private const MSG_ENABLE_DL_OFF = 'Dynamically loaded extensions aren\'t enabled';

    public function __construct()
    {
        parent::__construct('dl');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('dl() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('dl() requires a string filename in this compiler build');
        }
        if (null !== $frame->vmContext) {
            $frame->vmContext->errors->triggerError(
                self::MSG_ENABLE_DL_OFF,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
        $frame->returnVar->bool(false);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('dl() is VM-only in this compiler build');
    }
}
