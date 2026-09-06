<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Func as CoreFunc;

/**
 * DateTime / DateInterval / DatePeriod mutation + unserialize compile-time meta (#36387).
 *
 * Extracted from {@see DateTimeConstructAndMutationMeta} so gen-0 split-TU can hollow a
 * smaller TU. Move-only Concern extract; adds missing {@see Func} import so
 * {@code CoreFunc\\Internal} instanceof checks resolve (were always false under the
 * parent trait namespace).
 *
 * php-src: ext/date/php_date.c (date_add / date_sub / date_modify / DateTime::diff),
 * ext/standard/var.c (php_var_unserialize) — move-only Concern extract; no new C ABI.
 */
trait DateTimeMutationAndUnserializeMeta
{
    /** Copy compile-time instant onto `$dt->format()` / getTimestamp receivers (#32691). */
    private function applyDateTimeLocalInstantToReceiver(Operand $receiverOp, JIT\Variable $receiverVar): void
    {
        // Typed property fetch temps reuse operand names across statements. An earlier
        // `new DateTime(Immutable)` must not stamp a later `(new Sub)->dt->format()` receiver
        // (#35802 peer #35752 — cross-class chained format SIGABRT).
        if (
            null !== $receiverVar->objectPropertyClassConstraint
            || null !== $receiverVar->objectPropertySlot
        ) {
            return;
        }
        $recvName = JIT\OperandName::resolve($receiverOp);
        if (null === $recvName || '' === $recvName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($recvName);
        $instant = $this->context->dateTimeLocalInstants[$resolved] ?? null;
        if (null === $instant) {
            $bound = $this->context->namedVariableBindings[$resolved] ?? null;
            if ($bound instanceof JIT\Variable && null !== $bound->compileTimeDateTimeTimestamp) {
                $instant = [
                    'timestamp' => (int) $bound->compileTimeDateTimeTimestamp,
                    'timezone' => $bound->compileTimeTimezoneName,
                    'microsecond' => (int) ($bound->compileTimeDateTimeMicrosecond ?? 0),
                    'className' => $bound->compileTimeDateTimeClassName ?? $bound->classUserType ?? 'DateTime',
                ];
            }
        }
        if (null === $instant) {
            return;
        }
        $receiverVar->compileTimeDateTimeTimestamp = $instant['timestamp'];
        $receiverVar->compileTimeDateTimeMicrosecond = (int) ($instant['microsecond'] ?? 0);
        $receiverVar->compileTimeTimezoneName = $instant['timezone'];
        $instantClass = $instant['className'] ?? 'DateTime';
        if (null === $receiverVar->classUserType || '' === $receiverVar->classUserType) {
            $receiverVar->classUserType = \is_string($instantClass) && '' !== $instantClass
                ? $instantClass
                : 'DateTime';
        }
        if (null === $receiverVar->compileTimeDateTimeClassName || '' === $receiverVar->compileTimeDateTimeClassName) {
            $receiverVar->compileTimeDateTimeClassName = $receiverVar->classUserType;
        }
        // Operand names are reused across statements — consume so a later unrelated
        // `(new Sub)->dt->format()` temp does not inherit this construct instant (#35802).
        unset($this->context->dateTimeLocalInstants[$resolved]);
    }

    /**
     * Stamp a FUNCCALL DateTime mutation arg from its named local (#33781).
     *
     * {@see applyDateTimeLocalInstantToReceiver} requires `$bound === $receiverVar`; outgoing
     * call args are often a distinct Variable instance from {@see Context::$namedVariableBindings}.
     */
    private function syncDateTimeInstantOntoMutationArg(Operand $receiverOp, JIT\Variable $receiverVar): void
    {
        if (
            null !== $receiverVar->objectPropertyClassConstraint
            || null !== $receiverVar->objectPropertySlot
        ) {
            return;
        }
        $recvName = JIT\OperandName::resolve($receiverOp);
        if (null === $recvName || '' === $recvName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($recvName);
        $bound = $this->context->namedVariableBindings[$resolved] ?? null;
        if ($bound instanceof JIT\Variable && $bound !== $receiverVar) {
            if (null !== $bound->compileTimeDateTimeTimestamp) {
                $receiverVar->compileTimeDateTimeTimestamp = $bound->compileTimeDateTimeTimestamp;
                $receiverVar->compileTimeDateTimeMicrosecond = $bound->compileTimeDateTimeMicrosecond;
                $receiverVar->compileTimeTimezoneName = $bound->compileTimeTimezoneName;
                $receiverVar->compileTimeDateTimeClassName = $bound->compileTimeDateTimeClassName;
                if (null === $receiverVar->classUserType || '' === $receiverVar->classUserType) {
                    $receiverVar->classUserType = $bound->classUserType ?? $bound->compileTimeDateTimeClassName ?? 'DateTime';
                }
            }
        }
        $instant = $this->context->dateTimeLocalInstants[$resolved] ?? null;
        if (null === $instant && $bound instanceof JIT\Variable && null !== $bound->compileTimeDateTimeTimestamp) {
            $instant = [
                'timestamp' => (int) $bound->compileTimeDateTimeTimestamp,
                'timezone' => $bound->compileTimeTimezoneName,
                'microsecond' => (int) ($bound->compileTimeDateTimeMicrosecond ?? 0),
                'className' => $bound->compileTimeDateTimeClassName ?? $bound->classUserType ?? 'DateTime',
            ];
        }
        if (null === $instant) {
            return;
        }
        $receiverVar->compileTimeDateTimeTimestamp = $instant['timestamp'];
        $receiverVar->compileTimeDateTimeMicrosecond = (int) ($instant['microsecond'] ?? 0);
        $receiverVar->compileTimeTimezoneName = $instant['timezone'];
        $instantClass = $instant['className'] ?? 'DateTime';
        if (null === $receiverVar->classUserType || '' === $receiverVar->classUserType) {
            $receiverVar->classUserType = \is_string($instantClass) && '' !== $instantClass
                ? $instantClass
                : 'DateTime';
        }
        if (null === $receiverVar->compileTimeDateTimeClassName || '' === $receiverVar->compileTimeDateTimeClassName) {
            $receiverVar->compileTimeDateTimeClassName = $receiverVar->classUserType;
        }
        unset($this->context->dateTimeLocalInstants[$resolved]);
    }

    /** Stamp a FUNCCALL DateInterval mutation arg from its named local (#33781). */
    private function syncDateIntervalStateOntoMutationArg(Operand $receiverOp, JIT\Variable $receiverVar): void
    {
        $recvName = JIT\OperandName::resolve($receiverOp);
        if (null === $recvName || '' === $recvName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($recvName);
        $bound = $this->context->namedVariableBindings[$resolved] ?? null;
        if ($bound instanceof JIT\Variable && $bound !== $receiverVar && \is_array($bound->compileTimeDateInterval)) {
            $receiverVar->compileTimeDateInterval = $bound->compileTimeDateInterval;
            if (null === $receiverVar->classUserType || '' === $receiverVar->classUserType) {
                $receiverVar->classUserType = 'DateInterval';
            }

            return;
        }
        $state = $this->context->dateIntervalLocalStates[$resolved] ?? null;
        if (!\is_array($state)) {
            $bound = $this->context->namedVariableBindings[$resolved] ?? null;
            if ($bound instanceof JIT\Variable && \is_array($bound->compileTimeDateInterval)) {
                $state = $bound->compileTimeDateInterval;
            }
        }
        if (!\is_array($state)) {
            return;
        }
        $receiverVar->compileTimeDateInterval = $state;
        if (null === $receiverVar->classUserType || '' === $receiverVar->classUserType) {
            $receiverVar->classUserType = 'DateInterval';
        }
    }

    /** Procedural/method DateTime interval mutation — not a format() receiver (#33781). */
    private function isDateTimeMutationJitCall(?JIT\Call $toCall): bool
    {
        if ($toCall instanceof JIT\Call\DateTimeAdd
            || $toCall instanceof JIT\Call\DateTimeSub
            || $toCall instanceof JIT\Call\DateTimeModify
            || $toCall instanceof JIT\Call\ProceduralDateAdd
            || $toCall instanceof JIT\Call\ProceduralDateSub
        ) {
            return true;
        }
        if ($toCall instanceof CoreFunc\Internal) {
            return \in_array(strtolower($toCall->getName()), ['date_add', 'date_sub', 'date_modify'], true);
        }

        return false;
    }

    /**
     * FUNCCALL ARG_SEND temps for `$dt` / `$interval` are often distinct {@see Variable}
     * instances from {@see Context::$namedVariableBindings} — method `$this` is not.
     * Lower through the named binding so DateTime stamps reach {@see JitDateMutation} (#33781).
     *
     * @param list<Variable>     $callArgs
     * @param list<Operand|null> $callOperands
     *
     * @return list<Variable>
     */
    private function canonicalizeDateMutationCallArgs(array $callArgs, array $callOperands): array
    {
        foreach ([0, 1] as $idx) {
            if (!isset($callArgs[$idx], $callOperands[$idx])) {
                continue;
            }
            $arg = $callArgs[$idx];
            $operand = $callOperands[$idx];
            if (!$arg instanceof JIT\Variable || !$operand instanceof \PHPCfg\Operand) {
                continue;
            }
            $name = JIT\OperandName::resolve($operand);
            if (null === $name || '' === $name) {
                continue;
            }
            $bound = $this->context->namedVariableBindings[$this->context->resolveRefAliasName($name)] ?? null;
            if ($bound instanceof JIT\Variable && $bound !== $arg) {
                $callArgs[$idx] = $bound;
            }
        }

        return $callArgs;
    }

    /** Copy construct stamp onto `$i->format()` receivers (#32699). */
    private function applyDateIntervalStateToReceiver(Operand $receiverOp, JIT\Variable $receiverVar): void
    {
        $recvName = JIT\OperandName::resolve($receiverOp);
        if (null === $recvName || '' === $recvName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($recvName);
        $state = $this->context->dateIntervalLocalStates[$resolved] ?? null;
        if (!\is_array($state)) {
            return;
        }
        $receiverVar->compileTimeDateInterval = $state;
        if (null === $receiverVar->classUserType || '' === $receiverVar->classUserType) {
            $receiverVar->classUserType = 'DateInterval';
        }
    }

    /**
     * Publish compile-time DateInterval state onto the result local for format() (#33912 / #34599).
     *
     * Sources: DateTime::diff stamp, or unserialize() DateInterval wire fold.
     */
    private function syncDateTimeDiffMetaToResult(?JIT\Call $toCall, Operand $resultOp): void
    {
        $fromDiff = $toCall instanceof JIT\Call\DateTimeDiff;
        $fromUnserialize = $toCall instanceof CoreFunc\Internal
            && 'unserialize' === $toCall->getName();
        if (!$fromDiff && !$fromUnserialize) {
            return;
        }
        $state = $this->context->lastDateIntervalDiffState;
        if (\is_array($state)) {
            if (!$this->context->hasVariableOp($resultOp)) {
                $this->context->lastDateIntervalDiffState = null;
                $this->context->lastUnserializeObjectClassUserType = null;

                return;
            }
            $resultVar = $this->context->getVariableFromOp($resultOp);
            $resultVar->compileTimeDateInterval = $state;
            $resultVar->classUserType = 'DateInterval';
            $name = JIT\OperandName::resolve($resultOp);
            if (null !== $name && '' !== $name) {
                $resolved = $this->context->resolveRefAliasName($name);
                $this->context->bindVariableByName($resolved, $resultVar);
                $this->context->dateIntervalLocalStates[$resolved] = $state;
            }
            foreach ($this->context->namedVariableBindings as $boundName => $bound) {
                if ($bound === $resultVar) {
                    $bound->compileTimeDateInterval = $state;
                    $bound->classUserType = 'DateInterval';
                    $this->context->dateIntervalLocalStates[$boundName] = $state;
                }
            }
            $this->context->lastDateIntervalDiffState = null;
            $this->context->lastUnserializeObjectClassUserType = null;

            return;
        }
        // file_get_contents / true runtime O:DateInterval — classUserType only (#34602).
        $hint = $this->context->lastUnserializeObjectClassUserType;
        if (!$fromUnserialize || !\is_string($hint) || '' === $hint) {
            return;
        }
        $this->context->lastUnserializeObjectClassUserType = null;
        if (!$this->context->hasVariableOp($resultOp)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($resultOp);
        $resultVar->classUserType = $hint;
        $name = JIT\OperandName::resolve($resultOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            $this->context->bindVariableByName($resolved, $resultVar);
        }
        foreach ($this->context->namedVariableBindings as $boundName => $bound) {
            if ($bound === $resultVar) {
                $bound->classUserType = $hint;
            }
        }
    }

    /**
     * Publish folded DatePeriod unserialize foreach snapshot onto the result local (#34608).
     */
    private function syncDatePeriodUnserializeMetaToResult(?JIT\Call $toCall, Operand $resultOp): void
    {
        if (!($toCall instanceof CoreFunc\Internal) || 'unserialize' !== $toCall->getName()) {
            return;
        }
        $timestamps = $this->context->lastDatePeriodUnserializeTimestamps;
        if (!\is_array($timestamps)) {
            return;
        }
        $this->context->lastDatePeriodUnserializeTimestamps = null;
        $tz = $this->context->lastDatePeriodUnserializeTimezone ?? 'UTC';
        $this->context->lastDatePeriodUnserializeTimezone = null;
        if (!$this->context->hasVariableOp($resultOp)) {
            return;
        }
        $resultVar = $this->context->getVariableFromOp($resultOp);
        $resultVar->compileTimeDatePeriodTimestamps = $timestamps;
        $resultVar->compileTimeDatePeriodTimezone = $tz;
        $resultVar->classUserType = 'DatePeriod';
        $name = JIT\OperandName::resolve($resultOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            $this->context->bindVariableByName($resolved, $resultVar);
        }
        foreach ($this->context->namedVariableBindings as $boundName => $bound) {
            if ($bound === $resultVar) {
                $bound->compileTimeDatePeriodTimestamps = $timestamps;
                $bound->compileTimeDatePeriodTimezone = $tz;
                $bound->classUserType = 'DatePeriod';
            }
        }
        // Consume class hint if fold also set it (#34602 / #34608).
        if ('DatePeriod' === ($this->context->lastUnserializeObjectClassUserType ?? '')) {
            $this->context->lastUnserializeObjectClassUserType = null;
        }
    }

    /**
     * Publish folded DateTime / DateTimeImmutable unserialize stamps onto the result local (#34614).
     *
     * Without these, format('c') hits the UTC civil bake and getOffset() returns 0 while
     * getTimezone()->getName() still shows the IANA id (peer construct stamps #33939).
     *
     * FUNCCALL result operands are often unnamed temps; mirror
     * {@see syncDateTimeConstructMetaToAliases} publish-to-named-local so `$u = unserialize(...)`
     * receives the stamps (not only the temp).
     */
    private function syncDateTimeUnserializeMetaToResult(?JIT\Call $toCall, Operand $resultOp): void
    {
        if (!($toCall instanceof CoreFunc\Internal) || 'unserialize' !== $toCall->getName()) {
            return;
        }
        $instantIn = $this->context->lastDateTimeUnserializeInstant;
        if (!\is_array($instantIn)) {
            return;
        }
        $this->context->lastDateTimeUnserializeInstant = null;
        $ts = (int) $instantIn['timestamp'];
        $micro = (int) ($instantIn['microsecond'] ?? 0);
        $tz = (string) $instantIn['timezone'];
        $className = (string) ($instantIn['className'] ?? 'DateTime');
        if ('' === $tz) {
            $tz = 'UTC';
        }
        $instant = [
            'timestamp' => $ts,
            'timezone' => $tz,
            'microsecond' => $micro,
        ];
        $stamp = static function (JIT\Variable $bound) use ($ts, $micro, $tz, $className): void {
            $bound->compileTimeDateTimeTimestamp = $ts;
            $bound->compileTimeDateTimeMicrosecond = $micro;
            $bound->compileTimeTimezoneName = $tz;
            $bound->compileTimeDateTimeClassName = $className;
            $bound->classUserType = $className;
        };

        $resultVar = null;
        if ($this->context->hasVariableOp($resultOp)) {
            $resultVar = $this->context->getVariableFromOp($resultOp);
            $stamp($resultVar);
        }

        $publishName = JIT\OperandName::resolve($resultOp);
        if (null === $publishName || '' === $publishName) {
            foreach ($this->context->namedVariableBindings as $boundName => $bound) {
                if ($bound === $resultVar) {
                    $publishName = $boundName;
                    break;
                }
            }
        }
        if (null === $publishName || '' === $publishName) {
            // Prefer a DateTime-shaped local that still lacks a stamp (peer #32691 / #34461).
            foreach ($this->context->namedVariableBindings as $boundName => $bound) {
                if (!$bound instanceof JIT\Variable) {
                    continue;
                }
                if (null !== $bound->compileTimeDateTimeTimestamp) {
                    continue;
                }
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
                    // pending object slot
                } elseif (JIT\Variable::TYPE_VALUE === $bound->type && '' === $hint && '' === $legacy) {
                    // unserialize often yields TYPE_VALUE box into a pending local
                } else {
                    continue;
                }
                $publishName = $boundName;
                $stamp($bound);
                break;
            }
        }
        if (null !== $publishName && '' !== $publishName) {
            $resolved = $this->context->resolveRefAliasName($publishName);
            $existing = $this->context->namedVariableBindings[$resolved] ?? null;
            $existingIsArray = $existing instanceof JIT\Variable && (
                JIT\Variable::TYPE_HASHTABLE === $existing->type
                || !empty($existing->compileTimeEmptyArrayLiteral)
                || !empty($existing->valueBoxHashtable)
            );
            $publishVar = $resultVar ?? $existing;
            if ($publishVar instanceof JIT\Variable
                && (!$existingIsArray || $existing === $resultVar)
            ) {
                $stamp($publishVar);
                $this->context->bindVariableByName($resolved, $publishVar);
                $this->context->dateTimeLocalInstants[$resolved] = $instant;
                $this->context->lastDateTimeUnserializeLocalName = $resolved;
                if ($resultOp instanceof \PHPCfg\Operand) {
                    $this->context->scope->variables[$resultOp] = $publishVar;
                }
                // Method $this is loaded from scope->variables[named operand], which can be a
                // different Variable than namedVariableBindings (#34614). Stamp every scope
                // entry whose name resolves to this local so format()/getOffset() see the zone.
                foreach ($this->context->scope->variables as $scopeOp => $scopeVar) {
                    if (!$scopeVar instanceof JIT\Variable) {
                        continue;
                    }
                    $scopeName = JIT\OperandName::resolve($scopeOp);
                    if (null === $scopeName || '' === $scopeName) {
                        continue;
                    }
                    if ($this->context->resolveRefAliasName($scopeName) !== $resolved) {
                        continue;
                    }
                    $stamp($scopeVar);
                }
            }
        }
        if ($resultVar instanceof JIT\Variable) {
            foreach ($this->context->namedVariableBindings as $boundName => $bound) {
                if ($bound === $resultVar) {
                    $stamp($bound);
                    $this->context->dateTimeLocalInstants[$boundName] = $instant;
                }
            }
        }
    }

    /** Copy construct stamp onto `$z->getLocation()` receivers (#33727 / peer #29732). */
    private function applyDateTimeZoneLocalToReceiver(Operand $receiverOp, JIT\Variable $receiverVar): void
    {
        // DateTime(Immutable) receivers already got their zone from
        // dateTimeLocalInstants in applyDateTimeLocalInstantToReceiver. Do not
        // overwrite with construct-time dateTimeZoneLocalNames — that made
        // setTimezone a silent no-op for format()/getOffset() (#33939).
        if (null !== $receiverVar->compileTimeDateTimeTimestamp) {
            return;
        }
        $hint = strtolower(ltrim((string) ($receiverVar->classUserType ?? ''), '\\'));
        if ('datetime' === $hint || 'datetimeimmutable' === $hint) {
            return;
        }
        $recvName = JIT\OperandName::resolve($receiverOp);
        if (null === $recvName || '' === $recvName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($recvName);
        $zoneId = $this->context->dateTimeZoneLocalNames[$resolved] ?? null;
        if (null === $zoneId || '' === $zoneId) {
            $bound = $this->context->namedVariableBindings[$resolved] ?? null;
            if ($bound instanceof JIT\Variable && null !== $bound->compileTimeTimezoneName && '' !== $bound->compileTimeTimezoneName) {
                $zoneId = $bound->compileTimeTimezoneName;
            }
        }
        if (null === $zoneId || '' === $zoneId) {
            return;
        }
        $receiverVar->compileTimeTimezoneName = $zoneId;
        $this->context->dateTimeZoneLocalNames[$resolved] = $zoneId;
        if (null === $receiverVar->classUserType || '' === $receiverVar->classUserType) {
            $receiverVar->classUserType = 'DateTimeZone';
        }
        if (
            null === $receiverVar->compileTimeString
            || 'DateTimeZone' === $receiverVar->compileTimeString
        ) {
            $receiverVar->compileTimeString = $zoneId;
        }
    }
}
