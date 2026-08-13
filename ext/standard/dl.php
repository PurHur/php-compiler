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
 * dl() — runtime extension load stub (ext/standard/dl.c parity, issues #3591/#3779/#30250).
 *
 * v1: enable_dl is always off; emit Zend warning and return false (no .so loading).
 *
 * Z_PARAM_STR $extension_filename — soft-null DEP+coerce outside caller strict_types;
 * TypeError under declare(strict_types=1) before enablement Warning (#30250).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/dl.c PHP_FUNCTION(dl)
 * @see https://github.com/php/php-src/blob/master/ext/standard/basic_functions.stub.php
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
        // Z_PARAM_STR — honor caller strict_types (#30250); soft DEP+coerce otherwise.
        VmString::stringBuiltinArgForFrame($frame, 0, 'dl', 0, 'extension_filename', false);
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
