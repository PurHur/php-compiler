<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_diff_key() — key-only diff (php-src ext/standard/array.c, issue #4188).
 */
final class array_diff_key extends Internal
{
    use VmArrayAssocSetOps;

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_diff_key() expects at least 1 argument, 0 given');
        }
        $firstHt = VmArray::requireArrayParam($frame->calledArgs[0], 'array_diff_key', 1, 'array');
        $operandTables = [$firstHt];
        if ($argc > 1) {
            $operandTables = array_merge($operandTables, self::collectTypedOtherHashTables($frame->calledArgs, 'array_diff_key'));
        }
        VmArray::rejectEnumCaseSetOpOperands($frame, ...$operandTables);
        if (1 === $argc) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->array($firstHt->replaceCopy());
            }

            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $others = self::collectTypedOtherHashTables($frame->calledArgs, 'array_diff_key');
        $out = new HashTable();
        foreach ($firstHt->iterateKeyed(true) as [$key, $value]) {
            if (self::keyInAnyOther($key, $others)) {
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $stored);
            } else {
                $out->add($key->toString(), $stored);
            }
        }
        $frame->returnVar->array($out);
    }

    /**
     * @param list<\PHPCompiler\VM\Variable> $calledArgs
     *
     * @return list<HashTable>
     */
    private static function collectTypedOtherHashTables(array $calledArgs, string $fn): array
    {
        $others = [];
        for ($i = 1, $n = \count($calledArgs); $i < $n; ++$i) {
            $others[] = VmArray::requireArrayParam($calledArgs[$i], $fn, $i + 1, 'array');
        }

        return $others;
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_diff_key() expects at least 1 argument, 0 given');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_diff_key() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::arrayDiffKey($context, ...$args);
    }
}
