<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** preg_split() — VM via VmPreg; JIT/AOT via __compiler_preg_split (issue #1178). */
final class preg_split extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('preg_split() requires two to four arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if ($argc >= 3) {
            throw new \LogicException(
                'preg_split() limit and flags are not supported in VM in this compiler build (issue #1178)'
            );
        }
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_split() pattern');
        $subjectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $subjectVar->type) {
            throw new \LogicException('preg_split() subject must be a string in this compiler build');
        }
        $parts = VmPreg::pregSplit($pattern, $subjectVar->toString());
        if (false === $parts) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($parts as $part) {
            $value = new Variable();
            $value->string($part);
            $ht->append($value);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException(
                'preg_split() in JIT/AOT supports exactly two arguments (no limit or flags) in this compiler build'
            );
        }

        return JitPregSplit::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'preg_split() pattern'),
            JitStringArg::lower($context, $args[1], 'preg_split() subject')
        );
    }
}
