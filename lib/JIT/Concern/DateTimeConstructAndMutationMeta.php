<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;

/**
 * DateTime / DateInterval / DatePeriod construct compile-time meta (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code syncDateTimeZoneConstructMetaToAliases}
 * through {@code syncDatePeriodConstructMetaToAliases}. Mutation / unserialize receivers
 * live in {@see DateTimeMutationAndUnserializeMeta}.
 */
trait DateTimeConstructAndMutationMeta
{
    /**
     * @param list<JIT\Variable|array{unpack: JIT\Variable}> $callArgs
     * @param list<\PHPCfg\Operand|null>|null $callOperands
     */
    private function syncDateTimeZoneConstructMetaToAliases(
        ?JIT\Call $toCall,
        array $callArgs,
        ?array $callOperands = null
    ): void {
        if (!$toCall instanceof JIT\Call\DateTimeZoneConstruct) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $first = $callArgs[0];
        if (is_array($first)) {
            $first = $first['unpack'] ?? null;
        }
        if (!$first instanceof JIT\Variable || null === $first->compileTimeTimezoneName) {
            return;
        }
        $stamp = static function (JIT\Variable $bound) use ($first): void {
            $bound->compileTimeTimezoneName = $first->compileTimeTimezoneName;
            $bound->classUserType = $first->classUserType ?? 'DateTimeZone';
            if (
                null === $bound->compileTimeString
                || 'DateTimeZone' === $bound->compileTimeString
            ) {
                $bound->compileTimeString = $first->compileTimeTimezoneName;
            }
        };
        $stamp($first);
        $resultVar = $this->context->lastDateTimeZoneNewResultVar;
        if ($resultVar instanceof JIT\Variable) {
            $stamp($resultVar);
        }
        $resultOp = $this->context->lastDateTimeZoneNewResultOp;
        if ($resultOp instanceof \PHPCfg\Operand) {
            $this->context->scope->variables[$resultOp] = $first;
            $name = JIT\OperandName::resolve($resultOp);
            if (null !== $name && '' !== $name) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($name),
                    $first
                );
            }
        }
        // Stamp the named local that received the New DateTimeZone assign (#29732).
        $localName = $this->context->lastAssignedDateTimeZoneLocalName;
        if (null !== $localName && isset($this->context->namedVariableBindings[$localName])) {
            $stamp($this->context->namedVariableBindings[$localName]);
            $this->context->bindVariableByName($localName, $first);
            $this->context->dateTimeZoneLocalNames[$localName] = (string) $first->compileTimeTimezoneName;
        }
        if (null !== $resultOp && null !== ($n = JIT\OperandName::resolve($resultOp)) && '' !== $n) {
            $this->context->dateTimeZoneLocalNames[$this->context->resolveRefAliasName($n)] =
                (string) $first->compileTimeTimezoneName;
        }
        // Also stamp preallocated locals that still carry the New_ class-name hint.
        foreach ($this->context->namedVariableBindings as $boundName => $bound) {
            if ($bound === $first) {
                $this->context->dateTimeZoneLocalNames[$boundName] = (string) $first->compileTimeTimezoneName;
                continue;
            }
            if (
                'DateTimeZone' === ($bound->classUserType ?? '')
                || 'DateTimeZone' === ($bound->compileTimeString ?? '')
            ) {
                $stamp($bound);
                $this->context->dateTimeZoneLocalNames[$boundName] = (string) $first->compileTimeTimezoneName;
            }
        }
        $this->context->lastDateTimeZoneNewResultOp = null;
        $this->context->lastDateTimeZoneNewResultVar = null;
        $this->context->lastAssignedDateTimeZoneLocalName = null;
    }

    /**
     * If `new DateTime` / `DateTimeImmutable` feeds `$name = <new>`, bind `$name` to the New_
     * result before __construct sync. Prevents empty-hint from claiming a later preallocated
     * local (`$a` in `$out=[]; $p=new …; $a=[]; …`) (#34461 / re-#27309).
     *
     * TYPE_ASSIGN layout: arg1/arg2 = lvalue(s), arg3 = RHS (see assignRhsSlot).
     */
    private function prebindDateTimeNewAssignTarget(
        Block $block,
        OpCode $newOp,
        Operand $resultOp,
        JIT\Variable $resultVar
    ): void {
        $existingName = JIT\OperandName::resolve($resultOp);
        if (null !== $existingName && '' !== $existingName) {
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($existingName),
                $resultVar
            );

            return;
        }
        $newSlot = $block->slotForOperand($resultOp);
        if (null === $newSlot) {
            return;
        }
        $ops = $block->opCodes;
        $idx = array_search($newOp, $ops, true);
        if (false === $idx) {
            return;
        }
        $n = \count($ops);
        for ($i = (int) $idx + 1; $i < $n && $i < (int) $idx + 48; ++$i) {
            $next = $ops[$i];
            if (OpCode::TYPE_ASSIGN !== $next->type) {
                // Skip construct / arg-send noise between New_ and Assign.
                if (
                    OpCode::TYPE_ARG_SEND === $next->type
                    || OpCode::TYPE_FUNCCALL_INIT === $next->type
                    || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $next->type
                    || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $next->type
                    || OpCode::TYPE_NEW === $next->type
                ) {
                    continue;
                }
                // Other ops may sit between New_ and `$p = <temp>`; keep scanning.
                continue;
            }
            $rhsSlot = null !== $next->arg3 ? (int) $next->arg3 : (null !== $next->arg2 ? (int) $next->arg2 : -1);
            if ($rhsSlot !== (int) $newSlot) {
                continue;
            }
            foreach ([$next->arg2, $next->arg1] as $lhsSlot) {
                if (null === $lhsSlot) {
                    continue;
                }
                $lhsOp = $block->getOperand((int) $lhsSlot);
                $lhsName = JIT\OperandName::resolve($lhsOp);
                if (null === $lhsName || '' === $lhsName) {
                    continue;
                }
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($lhsName),
                    $resultVar
                );

                return;
            }

            return;
        }
    }

    private function syncDateTimeConstructMetaToAliases(?JIT\Call $toCall, array $callArgs): void
    {
        if (
            !$toCall instanceof JIT\Call\DateTimeConstruct
            && !$toCall instanceof JIT\Call\DateTimeImmutableConstruct
        ) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $first = $callArgs[0];
        if (is_array($first)) {
            $first = $first['unpack'] ?? null;
        }
        if (!$first instanceof JIT\Variable || null === $first->compileTimeDateTimeTimestamp) {
            return;
        }
        $className = $toCall instanceof JIT\Call\DateTimeImmutableConstruct
            ? 'DateTimeImmutable'
            : 'DateTime';
        $stamp = static function (JIT\Variable $bound) use ($first, $className): void {
            $bound->compileTimeDateTimeTimestamp = $first->compileTimeDateTimeTimestamp;
            $bound->compileTimeDateTimeMicrosecond = $first->compileTimeDateTimeMicrosecond;
            $bound->compileTimeTimezoneName = $first->compileTimeTimezoneName;
            $bound->classUserType = $first->classUserType ?? $className;
            $bound->compileTimeDateTimeClassName = $first->compileTimeDateTimeClassName ?? $className;
        };
        $instant = [
            'timestamp' => (int) $first->compileTimeDateTimeTimestamp,
            'timezone' => $first->compileTimeTimezoneName,
            'microsecond' => (int) ($first->compileTimeDateTimeMicrosecond ?? 0),
            'className' => $className,
        ];
        $stamp($first);
        $resultVar = $this->context->lastDateTimeNewResultVar;
        $resultOp = $this->context->lastDateTimeNewResultOp;
        if ($resultOp instanceof \PHPCfg\Operand && $this->context->hasVariableOp($resultOp)) {
            // EXEC_RETURN may replace the New_ shell with JitDateTimeConstruct's __value__* box
            // (#35752). Never revert scope[$resultOp] to construct $this (#35802).
            $live = $this->context->getVariableFromOp($resultOp);
            $stamp($live);
            $this->context->scope->variables[$resultOp] = $live;
            $resultVar = $live;
        } elseif ($resultVar instanceof JIT\Variable) {
            $stamp($resultVar);
        }
        $publishName = null;
        if ($resultOp instanceof \PHPCfg\Operand) {
            if (!$this->context->hasVariableOp($resultOp)) {
                $this->context->scope->variables[$resultOp] = $first;
            }
            $publishName = JIT\OperandName::resolve($resultOp);
        }
        // Temporary New_ results often have no Operand name (#32691 / re-#27309). Prefer a
        // named binding that is literally this Variable; else the first DateTime-shaped
        // local that still lacks a stamp (so `$a = new …; $b = new …` does not clobber `$a`
        // when `$b` is constructed — peer #33744).
        if (null === $publishName || '' === $publishName) {
            foreach ($this->context->namedVariableBindings as $boundName => $bound) {
                if ($bound === $first || $bound === $resultVar) {
                    $publishName = $boundName;
                    break;
                }
            }
        }
        if (null === $publishName || '' === $publishName) {
            foreach ($this->context->namedVariableBindings as $boundName => $bound) {
                if (!$bound instanceof JIT\Variable) {
                    continue;
                }
                if (null !== $bound->compileTimeDateTimeTimestamp) {
                    continue;
                }
                // Empty hint is only for unboxed pending DateTime *object* slots (re-#27309).
                // Never claim TYPE_VALUE locals: `$out = []` / preallocated `$a` before
                // `$a = []` are TYPE_VALUE and were stolen by nested DateTimeImmutable New_
                // inside `new DatePeriod(...)` (#34461). DateTime-tagged VALUE locals still
                // match via the hint/legacy checks below (assignOperand may box New_, #33876).
                $hint = strtolower(ltrim((string) ($bound->classUserType ?? ''), '\\'));
                $legacy = (string) ($bound->compileTimeString ?? '');
                $dateTimeTagged = \in_array($hint, ['datetime', 'datetimeimmutable'], true)
                    || \in_array($legacy, ['DateTime', 'DateTimeImmutable'], true);
                if ($dateTimeTagged) {
                    if (
                        JIT\Variable::TYPE_OBJECT !== $bound->type
                        && JIT\Variable::TYPE_VALUE !== $bound->type
                    ) {
                        continue;
                    }
                } elseif (JIT\Variable::TYPE_OBJECT === $bound->type && '' === $hint && '' === $legacy) {
                    // pending unboxed New_ object slot
                } else {
                    continue;
                }
                $publishName = $boundName;
                $stamp($bound);
                break;
            }
        }
        // After #35752 the authoritative local is the EXEC_RETURN __value__* box ($resultVar),
        // not construct $this ($first). Binding $first let a later `new DateTime` reclaim the
        // first local lacking a stamp and overwrite dateTimeLocalInstants — DatePeriod ctor
        // then materialized the wrong start/end (#27572 regression re-#35752).
        $publishVar = $resultVar instanceof JIT\Variable ? $resultVar : $first;
        if (null !== $publishName && '' !== $publishName) {
            $resolved = $this->context->resolveRefAliasName($publishName);
            $existing = $this->context->namedVariableBindings[$resolved] ?? null;
            // Never replace a live array local with the DateTime New_ (#34461).
            $existingIsArray = $existing instanceof JIT\Variable && (
                JIT\Variable::TYPE_HASHTABLE === $existing->type
                || !empty($existing->compileTimeEmptyArrayLiteral)
                || !empty($existing->valueBoxHashtable)
            );
            if (!$existingIsArray || $existing === $first || $existing === $publishVar) {
                $this->context->bindVariableByName($resolved, $publishVar);
                $this->context->dateTimeLocalInstants[$resolved] = $instant;
            }
        }
        foreach ($this->context->namedVariableBindings as $boundName => $bound) {
            if ($bound === $first || $bound === $publishVar) {
                $stamp($bound);
                $this->context->dateTimeLocalInstants[$boundName] = $instant;
            }
        }
        $this->context->pendingDateTimePropertyInstant = $instant;
        $this->context->lastDateTimeNewResultOp = null;
        $this->context->lastDateTimeNewResultVar = null;
    }

    /**
     * Rebind `new DateInterval` result so format() sees compileTimeDateInterval (#32699).
     *
     * @param list<JIT\Variable|array{unpack: JIT\Variable}> $callArgs
     */
    private function syncDateIntervalConstructMetaToAliases(?JIT\Call $toCall, array $callArgs): void
    {
        if (!$toCall instanceof JIT\Call\DateIntervalConstruct) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $first = $callArgs[0];
        if (is_array($first)) {
            $first = $first['unpack'] ?? null;
        }
        if (!$first instanceof JIT\Variable || !\is_array($first->compileTimeDateInterval)) {
            return;
        }
        $stamp = static function (JIT\Variable $bound) use ($first): void {
            $bound->compileTimeDateInterval = $first->compileTimeDateInterval;
            $bound->classUserType = $first->classUserType ?? 'DateInterval';
        };
        $stamp($first);
        $resultVar = $this->context->lastDateIntervalNewResultVar;
        if ($resultVar instanceof JIT\Variable) {
            $stamp($resultVar);
        }
        $resultOp = $this->context->lastDateIntervalNewResultOp;
        if ($resultOp instanceof \PHPCfg\Operand) {
            $this->context->scope->variables[$resultOp] = $first;
            $name = JIT\OperandName::resolve($resultOp);
            if (null !== $name && '' !== $name) {
                $resolved = $this->context->resolveRefAliasName($name);
                $this->context->bindVariableByName($resolved, $first);
                $this->context->dateIntervalLocalStates[$resolved] = $first->compileTimeDateInterval;
            }
        }
        foreach ($this->context->namedVariableBindings as $boundName => $bound) {
            if ($bound === $first || 'DateInterval' === ($bound->classUserType ?? '')) {
                $stamp($bound);
                $this->context->dateIntervalLocalStates[$boundName] = $first->compileTimeDateInterval;
            }
        }
        $this->context->lastDateIntervalNewResultOp = null;
        $this->context->lastDateIntervalNewResultVar = null;
    }

    /**
     * Restore DateTime construct stamps on call args by local name (re-#27309 / peer #32691).
     *
     * Method `$this` is stamped in {@see initJitMethodCall} and prepended to {@see Scope::$args}
     * without an {@see Scope::$argOperands} entry. Pair operands from the end so `$a->diff($b)`
     * still restores `$b`'s instant (date_diff($a,$b) has matching lengths).
     *
     * @param list<JIT\Variable|array{unpack: JIT\Variable}|array{named: string, value: JIT\Variable}> $callArgs
     * @param list<Operand|null> $callOperands
     */
    private function applyDateTimeLocalInstantsToCallArgs(
        array $callArgs,
        array $callOperands,
        ?JIT\Call $toCall = null
    ): void {
        // DatePeriod::__construct restores start/end/interval via applyDateMetaToDatePeriodConstructArgs.
        if ($toCall instanceof JIT\Call\DatePeriodConstruct) {
            return;
        }
        $mutationCall = $this->isDateTimeMutationJitCall($toCall);
        $opOffset = \count($callArgs) - \count($callOperands);
        if ($opOffset < 0) {
            $opOffset = 0;
        }
        foreach ($callArgs as $i => $arg) {
            if (is_array($arg)) {
                $arg = $arg['value'] ?? $arg['unpack'] ?? null;
            }
            if (!$arg instanceof JIT\Variable) {
                continue;
            }
            $operand = $callOperands[$i - $opOffset] ?? null;
            if ($operand instanceof \PHPCfg\Operand) {
                // Method $this: initJitMethodCall already restored the construct instant.
                // Procedural date_add/date_sub: FUNCCALL arg Variable may differ from the
                // named binding by identity — still stamp the arg used in lowering (#33781).
                if ($mutationCall && 0 === $i) {
                    $this->syncDateTimeInstantOntoMutationArg($operand, $arg);
                } elseif ($mutationCall && 1 === $i) {
                    $this->syncDateIntervalStateOntoMutationArg($operand, $arg);
                } elseif (!$mutationCall) {
                    $this->applyDateTimeLocalInstantToReceiver($operand, $arg);
                }
                // Unnamed $this (php-cfg temp): restore only the last unserialize local
                // (#34614). Do NOT use "unique dateTimeLocalInstants" — that stamped
                // DateTimeImmutable mutate/fluent returns with the construct instant (#34651).
                if (null === $arg->compileTimeDateTimeTimestamp) {
                    $this->restoreUnserializeDateTimeInstantOnto($arg);
                }
                continue;
            }
            // Instance method $this is often absent from callOperands (opOffset>0). Scope
            // Variable for `$u->format()` can diverge from namedVariableBindings['u'] after
            // unserialize sync (#34614) — restore that local only, never an unrelated stamp.
            if (
                0 === $i
                && $opOffset > 0
                && null === $arg->compileTimeDateTimeTimestamp
            ) {
                $this->restoreUnserializeDateTimeInstantOnto($arg);
            }
        }
    }

    /**
     * Stamp method $this from {@see Context::$lastDateTimeUnserializeLocalName} only (#34614 / #34651).
     */
    private function restoreUnserializeDateTimeInstantOnto(JIT\Variable $arg): void
    {
        $last = $this->context->lastDateTimeUnserializeLocalName;
        if (!\is_string($last) || '' === $last || !isset($this->context->dateTimeLocalInstants[$last])) {
            return;
        }
        $instant = $this->context->dateTimeLocalInstants[$last];
        if (!\is_array($instant) || !isset($instant['timestamp'])) {
            return;
        }
        $arg->compileTimeDateTimeTimestamp = (int) $instant['timestamp'];
        $arg->compileTimeDateTimeMicrosecond = (int) ($instant['microsecond'] ?? 0);
        $arg->compileTimeTimezoneName = $instant['timezone'] ?? null;
        if (null === $arg->classUserType || '' === $arg->classUserType) {
            $class = $instant['className'] ?? 'DateTime';
            $arg->classUserType = \is_string($class) && '' !== $class ? $class : 'DateTime';
        }
    }

    /**
     * Restore DateTime/DateInterval stamps on DatePeriod::__construct args by local name (#33744).
     *
     * @param list<JIT\Variable|array{unpack: JIT\Variable}|array{named: string, value: JIT\Variable}> $callArgs
     * @param list<Operand|null> $callOperands
     */
    private function applyDateMetaToDatePeriodConstructArgs(?JIT\Call $toCall, array $callArgs, array $callOperands): void
    {
        if (!$toCall instanceof JIT\Call\DatePeriodConstruct) {
            return;
        }
        // Same $this-vs-user-arg offset as applyDateTimeLocalInstantsToCallArgs (#34591).
        // Without it, callOperands[0] (start) is applied onto $this → DatePeriod inherits a
        // DateTime timestamp and serialize folds as O:8:"DateTime" instead of DatePeriod.
        $opOffset = \count($callArgs) - \count($callOperands);
        // Do not let the last standalone DateTime construct stamp DatePeriod::$start/$end (#35802).
        $this->context->pendingDateTimePropertyInstant = null;
        foreach ($callArgs as $i => $arg) {
            if (0 === $i) {
                continue;
            }
            if (is_array($arg)) {
                $arg = $arg['value'] ?? $arg['unpack'] ?? null;
            }
            if (!$arg instanceof JIT\Variable) {
                continue;
            }
            $operand = $callOperands[$i - $opOffset] ?? null;
            if (!$operand instanceof \PHPCfg\Operand) {
                continue;
            }
            $this->copyDateConstructMetaFromLocalName($operand, $arg);
        }
    }

    /** Restore DateTime/DateInterval compile-time stamps for DatePeriod ctor args by local name. */
    private function copyDateConstructMetaFromLocalName(\PHPCfg\Operand $operand, JIT\Variable $arg): void
    {
        $recvName = JIT\OperandName::resolve($operand);
        if (null === $recvName || '' === $recvName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($recvName);
        $bound = $this->context->namedVariableBindings[$resolved] ?? null;
        if ($bound instanceof JIT\Variable) {
            if (null !== $bound->compileTimeDateTimeTimestamp) {
                $arg->compileTimeDateTimeTimestamp = $bound->compileTimeDateTimeTimestamp;
                $arg->compileTimeDateTimeMicrosecond = $bound->compileTimeDateTimeMicrosecond;
                $arg->compileTimeTimezoneName = $bound->compileTimeTimezoneName;
                $arg->compileTimeDateTimeClassName = $bound->compileTimeDateTimeClassName;
                if (null === $arg->classUserType || '' === $arg->classUserType) {
                    $arg->classUserType = $bound->classUserType
                        ?? $bound->compileTimeDateTimeClassName
                        ?? 'DateTime';
                }
            }
            if (\is_array($bound->compileTimeDateInterval)) {
                $arg->compileTimeDateInterval = $bound->compileTimeDateInterval;
                if (null === $arg->classUserType || '' === $arg->classUserType) {
                    $arg->classUserType = 'DateInterval';
                }
            }
        }
        $instant = $this->context->dateTimeLocalInstants[$resolved] ?? null;
        if (\is_array($instant) && null === $arg->compileTimeDateTimeTimestamp) {
            $arg->compileTimeDateTimeTimestamp = (int) $instant['timestamp'];
            $arg->compileTimeDateTimeMicrosecond = (int) ($instant['microsecond'] ?? 0);
            $arg->compileTimeTimezoneName = $instant['timezone'] ?? null;
            $class = $instant['className'] ?? 'DateTime';
            $arg->compileTimeDateTimeClassName = \is_string($class) && '' !== $class ? $class : 'DateTime';
            if (null === $arg->classUserType || '' === $arg->classUserType) {
                $arg->classUserType = $arg->compileTimeDateTimeClassName;
            }
        }
        $interval = $this->context->dateIntervalLocalStates[$resolved] ?? null;
        if (\is_array($interval) && !\is_array($arg->compileTimeDateInterval)) {
            $arg->compileTimeDateInterval = $interval;
            if (null === $arg->classUserType || '' === $arg->classUserType) {
                $arg->classUserType = 'DateInterval';
            }
        }
    }

    /**
     * Copy the compile-time foreach snapshot from DatePeriod $this onto the New_ local (#33744).
     *
     * @param list<JIT\Variable|array{unpack: JIT\Variable}> $callArgs
     */
    private function syncDatePeriodConstructMetaToAliases(?JIT\Call $toCall, array $callArgs): void
    {
        if (!$toCall instanceof JIT\Call\DatePeriodConstruct) {
            return;
        }
        if ([] === $callArgs) {
            return;
        }
        $first = $callArgs[0];
        if (is_array($first)) {
            $first = $first['unpack'] ?? null;
        }
        if (!$first instanceof JIT\Variable
            || (null === $first->compileTimeDatePeriodTimestamps
                && !\is_array($first->compileTimeDatePeriodSerialize))) {
            return;
        }
        $stamp = static function (JIT\Variable $bound) use ($first): void {
            $bound->compileTimeDatePeriodTimestamps = $first->compileTimeDatePeriodTimestamps;
            $bound->compileTimeDatePeriodTimezone = $first->compileTimeDatePeriodTimezone;
            $bound->compileTimeDatePeriodSerialize = $first->compileTimeDatePeriodSerialize;
            $bound->classUserType = $first->classUserType ?? 'DatePeriod';
            // Do not retain a start-local DateTime instant on the period result (#34591).
            $bound->compileTimeDateTimeTimestamp = null;
            $bound->compileTimeDateTimeMicrosecond = null;
            $bound->compileTimeDateTimeClassName = null;
        };
        $stamp($first);
        $resultVar = $this->context->lastDatePeriodNewResultVar;
        if ($resultVar instanceof JIT\Variable) {
            $stamp($resultVar);
        }
        $resultOp = $this->context->lastDatePeriodNewResultOp;
        if ($resultOp instanceof \PHPCfg\Operand) {
            $this->context->scope->variables[$resultOp] = $first;
            $name = JIT\OperandName::resolve($resultOp);
            if (null !== $name && '' !== $name) {
                $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $first);
            }
        }
        foreach ($this->context->namedVariableBindings as $boundName => $bound) {
            if ($bound === $first || $bound === $resultVar) {
                $stamp($bound);
            }
        }
        $this->context->lastDatePeriodNewResultOp = null;
        $this->context->lastDatePeriodNewResultVar = null;
    }
}
