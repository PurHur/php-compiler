<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Value;

/**
 * dl() — runtime extension load stub (ext/standard/dl.c parity, issues #3591/#3779).
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
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'dl() expects exactly 1 argument, '.\max(0, $argc - 1).' given'
            );
        }
        VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'dl',
            0,
            'extension_filename'
        );
        if (null === $frame->returnVar) {
            return;
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
        return JitDl::invoke($context, ...$args);
    }
}
