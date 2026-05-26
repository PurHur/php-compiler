<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringSimilarText;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * similar_text() — similarity between two strings (subset of PHP; issue #2445).
 */
final class similar_text extends Internal
{
    public function __construct()
    {
        parent::__construct('similar_text');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('similar_text() accepts two or three arguments in this compiler build');
        }
        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_STRING !== $a->type || Variable::TYPE_STRING !== $b->type) {
            throw new \LogicException('similar_text() requires two strings in this compiler build');
        }
        $s1 = $a->toString();
        $s2 = $b->toString();
        if (3 === $argc) {
            $percent = 0.0;
            $sim = VmString::similar_text($s1, $s2, $percent);
            $frame->calledArgs[2]->resolveIndirect()->float($percent);
        } else {
            $sim = VmString::similar_text($s1, $s2);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($sim);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (\count($args) !== 2) {
            throw new \LogicException(
                'similar_text() JIT/AOT only supports two arguments in this compiler build; use bin/vm.php for &$percent'
            );
        }
        StringSimilarText::ensureLinked($context);
        $p0 = $this->stringDataPtr($context, $this->jitString($context, $args[0], 'similar_text() argument #1'));
        $p1 = $this->stringDataPtr($context, $this->jitString($context, $args[1], 'similar_text() argument #2'));
        $fn = $context->lookupFunction('phpc_similar_text');
        $i64 = $context->getTypeFromString('int64');
        $raw = $context->builder->call($fn, $p0, $p1);

        return $context->builder->sExt($raw, $i64);
    }
}
