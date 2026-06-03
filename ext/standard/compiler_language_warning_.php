<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Emit Zend-style E_WARNING for language constructs lowered in php-cfg (issue #4502).
 *
 * php-src: Zend/zend_compile.c — continue targeting switch warning.
 */
final class compiler_language_warning_ extends Internal
{
    public function __construct()
    {
        parent::__construct('compiler_language_warning');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('compiler_language_warning() requires VM context');
        }
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('compiler_language_warning() requires one or two arguments');
        }
        $messageVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $messageVar->type) {
            throw new \LogicException('compiler_language_warning() message must be a string');
        }
        $line = 0;
        if (2 === $argc) {
            $lineVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lineVar->type) {
                throw new \LogicException('compiler_language_warning() line must be an integer');
            }
            $line = $lineVar->toInt();
        }
        $file = '' !== $frame->scriptPath
            ? $frame->scriptPath
            : ('' !== $frame->vmContext->scriptStack->current()
                ? $frame->vmContext->scriptStack->current()
                : null);
        $frame->vmContext->errors->languageWarning(
            $messageVar->toString(),
            $file,
            $line,
            $frame->vmContext,
            $frame
        );
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('compiler_language_warning() is VM-only in this compiler build');
    }
}
