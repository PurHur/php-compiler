<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\ext\standard\VmStreamArg;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for APCu builtins (PECL apcu; #6574).
 */
abstract class ApcuFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            $this->getName().'() is not implemented for JIT in this compiler build (issue #6574)'
        );
    }

    protected static function parseKey(Frame $frame, string $fn, int $index, string $param): string
    {
        return VmString::coerceZparamStrBuiltinArg(
            $frame->calledArgs[$index],
            $fn,
            $index + 1,
            $param
        );
    }

    /**
     * @return list<string>|string
     */
    protected static function parseKeyOrKeyList(Frame $frame, string $fn, int $index, string $param): array|string
    {
        $var = $frame->calledArgs[$index]->resolveIndirect();
        if (Variable::TYPE_ARRAY === $var->type) {
            $keys = [];
            foreach ($var->toArray()->iterateKeyed(true) as [, $entry]) {
                $keys[] = VmString::coerceZparamStrBuiltinArg($entry, $fn, $index + 1, $param);
            }

            return $keys;
        }

        return self::parseKey($frame, $fn, $index, $param);
    }

    protected static function parseOptionalTtl(Frame $frame, string $fn, int $index): int
    {
        if (!isset($frame->calledArgs[$index])) {
            return 0;
        }

        return VmMath::parseIntBuiltinArgForFrame($frame, $index, $fn, $index + 1, 'ttl');
    }

    protected static function importCacheInfo(array $info): Variable
    {
        $ht = new HashTable();
        foreach ($info as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } elseif (\is_bool($value)) {
                $slot->bool($value);
            } elseif (\is_string($value)) {
                $slot->string($value);
            } elseif (\is_array($value)) {
                $inner = new HashTable();
                $i = 0;
                foreach ($value as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $rowHt = new HashTable();
                    foreach ($row as $rk => $rv) {
                        $cell = new Variable();
                        if (\is_int($rv)) {
                            $cell->int($rv);
                        } else {
                            $cell->string((string) $rv);
                        }
                        $rowHt->add((string) $rk, $cell);
                    }
                    $rowVar = new Variable();
                    $rowVar->array($rowHt);
                    $inner->addIndex($i, $rowVar);
                    ++$i;
                }
                $slot->array($inner);
            } else {
                $slot->null();
            }
            $ht->add((string) $key, $slot);
        }
        $var = new Variable();
        $var->array($ht);

        return $var;
    }

    protected static function typeName(Variable $var): string
    {
        return VmStreamArg::debugTypeName($var);
    }
}
