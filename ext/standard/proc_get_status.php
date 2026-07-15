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

/**
 * proc_get_status() — subprocess status (php-src ext/standard/proc_open.c; #3740).
 *
 * VM: {@see VmProcess::procGetStatus()}; JIT/AOT: __compiler_proc_get_status (#3740).
 */
final class proc_get_status extends Internal
{
    public function __construct()
    {
        parent::__construct('proc_get_status');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'proc_get_status', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $procVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = proc_close::requireProcessHandleForGetStatus($procVar, 'proc_get_status');
        $status = VmProcess::procGetStatus($handle);
        if (false === $status) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($status as $key => $value) {
            $slot = new Variable();
            self::assignStatusValue($slot, $value);
            $ht->add((string) $key, $slot);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \ArgumentCountError(\sprintf(
                'proc_get_status() expects exactly 1 argument, %d given',
                \count($args)
            ));
        }

        return JitProcGetStatus::invoke($context, $args[0]);
    }

    private static function assignStatusValue(Variable $slot, mixed $value): void
    {
        if (\is_bool($value)) {
            $slot->bool($value);

            return;
        }
        if (\is_int($value)) {
            $slot->int($value);

            return;
        }
        if (\is_string($value)) {
            $slot->string($value);

            return;
        }
        if (\is_array($value)) {
            $ht = new HashTable();
            foreach ($value as $index => $elem) {
                $elemSlot = new Variable();
                if (\is_int($elem)) {
                    $elemSlot->int($elem);
                } else {
                    $elemSlot->null();
                }
                $ht->add((string) $index, $elemSlot);
            }
            $slot->array($ht);

            return;
        }
        $slot->null();
    }
}
