<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** pathinfo() for file paths (subset of PHP; JIT/AOT via JitPathinfo). */
final class pathinfo extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('pathinfo() requires one or two arguments in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'pathinfo', 0, 'path');
        $flags = 15;
        if (2 === $argc) {
            $flagVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagVar->type) {
                throw new \LogicException('pathinfo() flags must be an integer in this compiler build');
            }
            $flags = $flagVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }

        $result = VmString::pathinfo($path, $flags);
        if (\is_array($result)) {
            $ht = new HashTable();
            foreach ($result as $key => $value) {
                $slot = new Variable();
                $slot->string((string) $value);
                $ht->add((string) $key, $slot);
            }
            $frame->returnVar->array($ht);

            return;
        }
        $frame->returnVar->string((string) $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('pathinfo() requires one or two arguments in this compiler build');
        }
        $flags = 2 === $argc ? $args[1] : null;

        return JitPathinfo::invoke($context, $args[0], $flags);
    }
}
