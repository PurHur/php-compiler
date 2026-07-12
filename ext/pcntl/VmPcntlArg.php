<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pcntl;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\posix\VmPosix;
use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\ext\standard\VmCallable;

final class VmPcntlArg
{
    public static function coerceIntArg(Variable $arg, string $function, int $position, string $name): int
    {
        return VmPosix::coerceIntArg($arg, $function, $position, $name);
    }

    public static function validateSignal(int $signo, string $function): void
    {
        if (!PcntlConstants::isValidSignal($signo)) {
            throw new \ValueError($function.'(): Invalid signal');
        }
    }

    /**
     * @return list<int>
     */
    public static function parseSignalList(Variable $arg, string $function, int $position, string $name): array
    {
        $ht = VmArray::requireArrayParam($arg, $function, $position + 1, $name);
        $signals = [];
        foreach ($ht->iterate(true) as $value) {
            $signals[] = VmPosix::coerceIntArg($value, $function, $position, $name);
        }

        return $signals;
    }

    public static function writeSignalList(array $signals, Variable $out): void
    {
        $ht = new HashTable();
        $idx = 0;
        foreach ($signals as $signo) {
            $var = new Variable();
            $var->int((int) $signo);
            $ht->addIndex($idx, $var);
            ++$idx;
        }
        $target = $out->byRefTarget();
        $target->array($ht);
    }

    public static function requireCallable(Context $context, Variable $callable, string $function, int $position): void
    {
        if (EnumCaseSupport::isEnumCaseVariable($callable->resolveIndirect())) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError());
        }
        if (!VmCallable::isCallable($context, $callable)) {
            throw new \TypeError(VmCallable::invalidCallbackTypeError());
        }
    }
}
