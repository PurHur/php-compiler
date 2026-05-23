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
 * strip_tags() for strings (subset of PHP; JIT/AOT via __string__strip_tags).
 */
final class strip_tags extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('strip_tags() requires one or two arguments in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('strip_tags() only supports strings in this compiler build');
        }
        $allowed = null;
        if (2 === $argc) {
            $allowVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL === $allowVar->type) {
                $allowed = null;
            } elseif (Variable::TYPE_STRING === $allowVar->type) {
                $allowed = $allowVar->toString();
            } else {
                throw new \LogicException('strip_tags() allowed_tags must be a string or null in this compiler build');
            }
        }
        $frame->returnVar->string(VmString::stripTags($v->toString(), $allowed));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('strip_tags() requires one or two arguments in this compiler build');
        }
        $allowed = 2 === $argc ? $args[1] : null;

        $subject = $this->jitString($context, $args[0], 'strip_tags() string');
        if (null === $allowed) {
            $allowPtr = $context->builder->load($context->constantStringFromString(''));
        } else {
            $allowPtr = $this->jitString($context, $allowed, 'strip_tags() allowed_tags');
        }

        return $context->builder->call(
            $context->lookupFunction('__compiler_strip_tags'),
            $subject,
            $allowPtr
        );
    }
}
