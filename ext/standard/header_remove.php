<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ResponseHeaders;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\ResponseContext;
use PHPLLVM\Value;

/**
 * header_remove() — remove pending response headers (issue #311).
 */
final class header_remove extends Internal
{
    public function __construct()
    {
        parent::__construct('header_remove');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('header_remove() accepts at most one argument');
        }
        if (0 === $argc) {
            ResponseContext::removeHeader(null);
            if (\function_exists('header_remove')) {
                \header_remove();
            }

            return;
        }
        $arg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $arg->type) {
            throw new \LogicException('header_remove() name must be a string in this compiler build');
        }
        $name = $arg->toString();
        ResponseContext::removeHeader($name);
        if (\function_exists('header_remove')) {
            \header_remove($name);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \LogicException('header_remove() accepts at most one argument');
        }
        if (0 === $argc) {
            ResponseHeaders::emitRemove($context, null);

            return $context->getTypeFromString('int32')->constInt(0, false);
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type) {
            throw new \LogicException('header_remove() name must be a string in this compiler build');
        }
        ResponseHeaders::emitRemove($context, $context->helper->loadValue($args[0]));

        return $context->getTypeFromString('int32')->constInt(0, false);
    }
}
