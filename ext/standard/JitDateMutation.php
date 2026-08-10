<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DateMutationRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for date_add/date_sub/date_modify/date_diff (#4604 phase 2).
 *
 * php-src: ext/date/php_date.c
 */
final class JitDateMutation
{
    private const DATETIME_TYPE_ERROR =
        '%s(): Argument #1 ($object) must be of type DateTime, %s given';

    private const INTERVAL_TYPE_ERROR =
        '%s(): Argument #2 ($interval) must be of type DateInterval, %s given';

    private const TARGET_TYPE_ERROR =
        'date_diff(): Argument #2 ($target) must be of type DateTime, %s given';

    public static function invokeAdd(Context $context, JITVariable ...$args): Value
    {
        return self::invokeIntervalMutation($context, 'date_add', true, ...$args);
    }

    public static function invokeSub(Context $context, JITVariable ...$args): Value
    {
        return self::invokeIntervalMutation($context, 'date_sub', false, ...$args);
    }

    public static function invokeModify(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError('date_modify() expects exactly 2 arguments, '.\count($args).' given');
        }

        return self::invokeObjectModify($context, false, 'date_modify', ...$args);
    }

    /**
     * DateTime::modify() / DateTimeImmutable::modify() / date_modify() (#26789, #27262).
     *
     * Prefer compile-time {@see VmDateTimeNative::modifyRelative} when the receiver carries
     * {@see JITVariable::$compileTimeLong} from construct. Fixed-length unit deltas (+N day/hour/…)
     * lower to pure LLVM add. Month/year use UTC civil IR ({@see JitGetdate}) — thin AOT cannot
     * NestedJIT {@see DateMutationRuntime} (missing {@see __nativearray__boundscheck} / empty
     * gmmktime helper box, peer #27159).
     *
     * Mutable: in-place timestamp update. Immutable: allocate+copy (or constant allocate) so the
     * original stays unchanged (php-src zim_DateTimeImmutable_modify).
     */
    public static function invokeObjectModify(
        Context $context,
        bool $immutable,
        string $function,
        JITVariable ...$args
    ): Value {
        if (\count($args) < 2) {
            throw new \LogicException($function.'() requires $this and a modifier argument');
        }

        // Method: Argument #1 ($modifier); procedural date_modify: Argument #2 (#29818).
        $modifierUserIndex = \str_contains($function, '::') ? 0 : 1;
        if (JITVariable::TYPE_NULL === $args[1]->type || $args[1]->isNullConstant) {
            // Z_PARAM_STR — strict TypeError IR; weak soft-null → "" (#29818).
            JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[1],
                $function,
                $modifierUserIndex,
                'modifier'
            );
            if ($context->callerStrictTypes) {
                // TypeError+abort already emitted; return unreachable object box.
                $ret = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $ret)
                );

                return $ret;
            }
            $modifierLit = '';
        } else {
            $modifierLit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
            if (null === $modifierLit) {
                JitStringBuiltinArg::lower($context, $args[1], $function, $modifierUserIndex, 'modifier');
                throw new \LogicException(
                    $function.'() requires a compile-time string modifier in this compiler build (#26789)'
                );
            }
        }

        $layout = $immutable ? 'DateTimeImmutable' : 'DateTime';
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;

        // Fast path: construct left compileTimeLong on $this — resolve entirely at compile time.
        if (null !== $args[0]->compileTimeLong) {
            $tzName = $args[0]->compileTimeString ?? 'UTC';
            // Timezone from construct; ignore leftover date-string stamps on receivers (#27309 peer).
            if ('' === $tzName || 1 === preg_match('/^\d{4}-\d{2}-\d{2}/', $tzName)) {
                $tzName = 'UTC';
            }
            try {
                $newTs = VmDateTimeNative::modifyRelative(
                    (int) $args[0]->compileTimeLong,
                    $modifierLit,
                    $tzName
                );
            } catch (\Throwable $e) {
                throw new \LogicException(
                    $function.'(): Failed to apply modifier at compile time: '.$e->getMessage(),
                    0,
                    $e
                );
            }

            if ($immutable) {
                $obj = self::allocateDateTimeLike($context, $layout, $newTs, 0, $tzName);
                $ret = JitValueBox::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeObject'),
                    JitValueBox::pointer($context, $ret),
                    $obj
                );

                return $ret;
            }

            $dtObj = self::requireDateTimeObject($context, $args[0], $function.'()');
            self::writeLongProp(
                $context,
                $object,
                $dtObj,
                $layout,
                DateTimeSupport::TS_PROPERTY,
                $context->getTypeFromString('int64')->constInt($newTs, false)
            );

            return self::returnObjectArg($context, $args[0]);
        }

        // Runtime receiver (#26789 day-units; #27262 month/year via modify_delta helper).
        $delta = self::parseModifyLiteral($modifierLit);
        $scale = self::fixedUnitScaleSeconds($delta['unit']);

        if ($immutable) {
            $receiver = ReflectionSetup::loadObjectFromArg($context, $args[0]);
            $classId = $object->lookup($layout);
            $target = $object->allocate($classId);
            ReflectionSetup::markConstructed($context, $target);
            foreach ([
                DateTimeSupport::TS_PROPERTY,
                DateTimeSupport::MICROSECOND_PROPERTY,
                DateTimeSupport::TZ_PROPERTY,
            ] as $prop) {
                $val = $object->propertyFetch($receiver, $layout, $prop);
                $object->propertyStore(
                    $object->propertySlotFor($target, $layout, $prop),
                    $val,
                    $val->type
                );
            }
            $dtObj = $target;
        } else {
            $dtObj = self::requireDateTimeObject($context, $args[0], $function.'()');
        }

        $ts = self::readLongProp($context, $object, $dtObj, $layout, DateTimeSupport::TS_PROPERTY);
        $i64 = $context->getTypeFromString('int64');
        if (null === $scale) {
            // Month/year: UTC civil add + mktime-style day overflow (#27262).
            $parts = JitGetdate::civilPartsPublic($context, $ts);
            $year = $parts['year'];
            $month = $parts['month'];
            if (5 === $delta['unit']) {
                $month = $context->builder->add($month, $i64->constInt($delta['amount'], true));
            } else {
                $year = $context->builder->add($year, $i64->constInt($delta['amount'], true));
            }
            $newTs = JitGetdate::timestampFromCivilPublic(
                $context,
                $year,
                $month,
                $parts['day'],
                $parts['hour'],
                $parts['minute'],
                $parts['second']
            );
        } else {
            $deltaSeconds = $delta['amount'] * $scale;
            $newTs = $context->builder->add($ts, $i64->constInt($deltaSeconds, true));
        }
        self::writeLongProp($context, $object, $dtObj, $layout, DateTimeSupport::TS_PROPERTY, $newTs);

        if ($immutable) {
            $ret = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                JitValueBox::pointer($context, $ret),
                $dtObj
            );

            return $ret;
        }

        return self::returnObjectArg($context, $args[0]);
    }

    /**
     * Seconds per unit for fixed-length modifiers (not month/year).
     * unit: 0=second, 1=minute, 2=hour, 3=day, 4=week, 5=month, 6=year
     */
    private static function fixedUnitScaleSeconds(int $unitCode): ?int
    {
        return match ($unitCode) {
            0 => 1,
            1 => 60,
            2 => 3600,
            3 => 86400,
            4 => 604800,
            default => null,
        };
    }

    private static function allocateDateTimeLike(
        Context $context,
        string $className,
        int $timestamp,
        int $microsecond,
        string $tzName
    ): Value {
        $objectType = $context->type->object;
        $classId = $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $i64 = $context->getTypeFromString('int64');
        $voidPtr = $context->getTypeFromString('void*');

        $tsPtr = $context->memory->malloc($i64);
        $context->builder->store($i64->constInt($timestamp, false), $tsPtr);
        $context->builder->store(
            $context->builder->pointerCast($tsPtr, $voidPtr),
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TS_PROPERTY)
        );

        $usPtr = $context->memory->malloc($i64);
        $context->builder->store($i64->constInt($microsecond, false), $usPtr);
        $context->builder->store(
            $context->builder->pointerCast($usPtr, $voidPtr),
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::MICROSECOND_PROPERTY)
        );

        $tzStr = $context->builder->load($context->constantStringFromString($tzName));
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $tzStr);
        $context->builder->store(
            $context->builder->pointerCast($owned, $voidPtr),
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TZ_PROPERTY)
        );

        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function invokeDiff(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                \sprintf('date_diff() expects at least 2 arguments, %d given', $argc)
            );
        }

        return self::lowerDiff($context, 'date_diff', ...$args);
    }

    /**
     * DateTime{,Immutable}::diff($this, $target, $absolute = false) — JIT/AOT (#27309).
     */
    public static function invokeDiffMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                \sprintf('DateTime::diff() expects at least 1 argument, %d given', max(0, $argc - 1))
            );
        }

        return self::lowerDiff($context, 'DateTime::diff', ...$args);
    }

    private static function lowerDiff(
        Context $context,
        string $function,
        JITVariable ...$args
    ): Value {
        $absolute = false;
        if (\count($args) >= 3) {
            if (null !== $args[2]->compileTimeBool) {
                $absolute = (bool) $args[2]->compileTimeBool;
            } elseif (JITVariable::TYPE_NATIVE_BOOL === $args[2]->type) {
                $absolute = true;
            }
        }

        $compileTime = self::tryCompileTimeDiff($context, $args[0], $args[1], $absolute);
        if (null === $compileTime) {
            throw new \LogicException(
                $function.'() requires compile-time DateTime receivers in this compiler build (#27309)'
            );
        }

        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');

        return self::materializeDateIntervalFromScalars(
            $context,
            $i64->constInt($compileTime['y'], true),
            $i64->constInt($compileTime['m'], true),
            $i64->constInt($compileTime['d'], true),
            $i64->constInt($compileTime['h'], true),
            $i64->constInt($compileTime['i'], true),
            $i64->constInt($compileTime['s'], true),
            $dbl->constReal($compileTime['f']),
            $i64->constInt($compileTime['invert'], true),
            $i64->constInt($compileTime['days'], true)
        );
    }

    /**
     * @return array{y: int, m: int, d: int, h: int, i: int, s: int, f: float, invert: int, days: int}|null
     */
    private static function tryCompileTimeDiff(
        Context $context,
        JITVariable $base,
        JITVariable $target,
        bool $absolute
    ): ?array {
        $baseInstant = self::resolveCompileTimeInstant($context, $base);
        $targetInstant = self::resolveCompileTimeInstant($context, $target);
        if (null === $baseInstant || null === $targetInstant) {
            return null;
        }

        return VmDateTimeNative::diffTimestamps(
            $baseInstant['timestamp'],
            $targetInstant['timestamp'],
            $baseInstant['timezone'],
            $absolute,
            $baseInstant['microsecond'],
            $targetInstant['microsecond']
        );
    }

    /**
     * Recover construct-time instant from a DateTime receiver / arg (#27309).
     *
     * Method `$this` is often TYPE_OBJECT without {@see JITVariable::$compileTimeLong};
     * the time literal may still sit on {@see JITVariable::$compileTimeString}.
     *
     * @return array{timestamp: int, microsecond: int, timezone: string}|null
     */
    private static function resolveCompileTimeInstant(Context $context, JITVariable $arg): ?array
    {
        if (null !== $arg->compileTimeLong) {
            $tz = $arg->compileTimeString;
            // Timezone from construct; ignore leftover date-string stamps on receivers.
            if (null === $tz || '' === $tz || 1 === preg_match('/^\d{4}-\d{2}-\d{2}/', $tz)) {
                $tz = 'UTC';
            }

            return [
                'timestamp' => (int) $arg->compileTimeLong,
                'microsecond' => 0,
                'timezone' => $tz,
            ];
        }

        $timeLit = $arg->compileTimeString;
        if (null === $timeLit || '' === $timeLit || 0 === preg_match('/^\d{4}-\d{2}-\d{2}/', $timeLit)) {
            return null;
        }

        $vmCtx = $context->runtime->vmContext;
        if (null === $vmCtx) {
            return null;
        }
        $created = DateTimeSupport::tryNewDateTimeVariable($vmCtx, $timeLit, null);
        if (null === $created) {
            return null;
        }
        $obj = $created->toObject();

        return [
            'timestamp' => $obj->getProperty(DateTimeSupport::TS_PROPERTY)->resolveIndirect()->toInt(),
            'microsecond' => $obj->getProperty(DateTimeSupport::MICROSECOND_PROPERTY)->resolveIndirect()->toInt(),
            'timezone' => $obj->getProperty(DateTimeSupport::TZ_PROPERTY)->resolveIndirect()->toString(),
        ];
    }

    private static function invokeIntervalMutation(
        Context $context,
        string $function,
        bool $add,
        JITVariable ...$args
    ): Value {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(
                \sprintf('%s() expects exactly 2 arguments, %d given', $function, \count($args))
            );
        }

        DateMutationRuntime::ensureLinked($context);

        $dtObj = self::requireDateTimeObject($context, $args[0], $function.'()');
        $intervalObj = self::requireDateIntervalObject($context, $args[1], $function.'()');

        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $ts = self::readLongProp($context, $object, $dtObj, 'DateTime', DateTimeSupport::TS_PROPERTY);
        $micro = self::readLongProp($context, $object, $dtObj, 'DateTime', DateTimeSupport::MICROSECOND_PROPERTY);
        $tz = self::readStringProp($context, $object, $dtObj, 'DateTime', DateTimeSupport::TZ_PROPERTY);
        $tzCstr = self::stringData($context, $tz);

        $iy = self::readLongProp($context, $object, $intervalObj, 'DateInterval', 'y');
        $im = self::readLongProp($context, $object, $intervalObj, 'DateInterval', 'm');
        $id = self::readLongProp($context, $object, $intervalObj, 'DateInterval', 'd');
        $ih = self::readLongProp($context, $object, $intervalObj, 'DateInterval', 'h');
        $ii = self::readLongProp($context, $object, $intervalObj, 'DateInterval', 'i');
        $is = self::readLongProp($context, $object, $intervalObj, 'DateInterval', 's');
        $if = self::readDoubleProp($context, $object, $intervalObj, 'DateInterval', 'f');
        $invert = self::readLongProp($context, $object, $intervalObj, 'DateInterval', 'invert');

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $i64p = $context->getTypeFromString('int64*');
        $outTs = $context->builder->alloca($i64, 1, 'date_ai_out_ts');
        $outMicro = $context->builder->alloca($i64, 1, 'date_ai_out_micro');
        $context->builder->call(
            $context->lookupFunction('__phpc_date_apply_interval'),
            $ts,
            $micro,
            $iy,
            $im,
            $id,
            $ih,
            $ii,
            $is,
            $if,
            $invert,
            $i1->constInt($add ? 1 : 0, false),
            $tzCstr,
            $outTs,
            $outMicro
        );

        $newTs = $context->builder->load($outTs);
        $newMicro = $context->builder->load($outMicro);
        self::writeLongProp($context, $object, $dtObj, 'DateTime', DateTimeSupport::TS_PROPERTY, $newTs);
        self::writeLongProp($context, $object, $dtObj, 'DateTime', DateTimeSupport::MICROSECOND_PROPERTY, $newMicro);

        return self::returnObjectArg($context, $args[0]);
    }

    /**
     * @return array{amount: int, unit: int}
     */
    private static function parseModifyLiteral(string $modifier): array
    {
        if (!preg_match(
            '/^([+-])\s*(\d+)\s+(second|seconds|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)$/i',
            trim($modifier),
            $matches
        )) {
            throw new \LogicException('date_modify(): Failed to parse modifier');
        }
        $sign = '-' === $matches[1] ? -1 : 1;
        $amount = $sign * (int) $matches[2];
        $unit = strtolower($matches[3]);
        if (str_ends_with($unit, 's')) {
            $unit = substr($unit, 0, -1);
        }

        $code = match ($unit) {
            'second' => 0,
            'minute' => 1,
            'hour' => 2,
            'day' => 3,
            'week' => 4,
            'month' => 5,
            'year' => 6,
            default => throw new \LogicException('date_modify(): Failed to parse modifier'),
        };

        return ['amount' => $amount, 'unit' => $code];
    }

    private static function materializeDateIntervalFromScalars(
        Context $context,
        Value $y,
        Value $m,
        Value $d,
        Value $h,
        Value $i,
        Value $s,
        Value $f,
        Value $invert,
        Value $days
    ): Value {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('DateInterval');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        foreach (['y' => $y, 'm' => $m, 'd' => $d, 'h' => $h, 'i' => $i, 's' => $s, 'invert' => $invert] as $name => $val) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, 'DateInterval', $name),
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $val
                ),
                JITVariable::TYPE_NATIVE_LONG
            );
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DateInterval', 'f'),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_DOUBLE,
                JITVariable::KIND_VALUE,
                $f
            ),
            JITVariable::TYPE_NATIVE_DOUBLE
        );
        // days is TYPE_VALUE (int|false); write the int from date_diff (#27309).
        $daysSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            JitValueBox::pointer($context, $daysSlot),
            $days
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, 'DateInterval', 'days'),
            new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $daysSlot),
            JITVariable::TYPE_VALUE
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeObject'), $ptr, $obj);

        return $ptr;
    }

    private static function returnObjectArg(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                $ptr,
                $arg->value
            );

            return $ptr;
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::copyFromPointer($context, $slot, $valuePtr);

        return $ptr;
    }

    private static function requireDateTimeObject(
        Context $context,
        JITVariable $arg,
        string $label,
        ?string $typeErrorTemplate = null
    ): Value {
        $template = $typeErrorTemplate ?? \sprintf(self::DATETIME_TYPE_ERROR, $label, '%s');
        $objPtr = self::requireObjectValue($context, $arg, $template, 'array');
        self::assertClassOneOf($context, $objPtr, ['DateTime'], $template, 'object');

        return $objPtr;
    }

    private static function requireDateIntervalObject(Context $context, JITVariable $arg, string $label): Value
    {
        $template = \sprintf(self::INTERVAL_TYPE_ERROR, $label, '%s');
        $objPtr = self::requireObjectValue($context, $arg, $template, 'array');
        self::assertClassOneOf($context, $objPtr, ['DateInterval'], $template, 'object');

        return $objPtr;
    }

    private static function requireObjectValue(
        Context $context,
        JITVariable $arg,
        string $typeErrorTemplate,
        string $nonObjectGiven
    ): Value {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return $arg->value;
        }
        if (JITVariable::TYPE_VALUE !== $arg->type) {
            self::emitTypeErrorAndAbort($context, \sprintf($typeErrorTemplate, self::typeLabel($arg->type)));

            return $context->getTypeFromString('__object__*')->constNull();
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $typeField));
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_OBJECT, false)
        );
        $okBlock = BasicBlockHelper::append($context, 'date_mut_obj_ok');
        $errBlock = BasicBlockHelper::append($context, 'date_mut_obj_err');
        $context->builder->branchIf($isObject, $okBlock, $errBlock);

        $context->builder->positionAtEnd($errBlock);
        self::emitTypeErrorAndAbort($context, \sprintf($typeErrorTemplate, $nonObjectGiven));

        $context->builder->positionAtEnd($okBlock);

        return $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
    }

    /**
     * @param list<string> $classNames
     */
    private static function assertClassOneOf(
        Context $context,
        Value $objPtr,
        array $classNames,
        string $typeErrorTemplate,
        string $wrongGiven
    ): void {
        /** @var ObjectBuiltin $object */
        $object = $context->type->object;
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load($context->builder->structGep($objPtr, $map['class_id']));
        $i64 = $context->getTypeFromString('int64');
        $tag = 'date_mut_class_'.spl_object_id($context);

        $failBlock = BasicBlockHelper::append($context, $tag.'_fail');
        $okBlock = BasicBlockHelper::append($context, $tag.'_ok');

        $last = \count($classNames) - 1;
        foreach ($classNames as $idx => $className) {
            $expectedId = $object->lookup($className);
            $matches = $context->builder->icmp(
                Builder::INT_EQ,
                $classId,
                $i64->constInt($expectedId, false)
            );
            $nextBlock = $last === $idx
                ? $failBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$idx);
            $context->builder->branchIf($matches, $okBlock, $nextBlock);
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($failBlock);
        self::emitTypeErrorAndAbort($context, \sprintf($typeErrorTemplate, $wrongGiven));

        $context->builder->positionAtEnd($okBlock);
    }

    private static function readStringProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        string $className,
        string $propName
    ): Value {
        $prop = $object->propertyFetch($objPtr, $className, $propName);
        if (JITVariable::TYPE_STRING === $prop->type) {
            return $context->builder->load($prop->value);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $prop->value
        );
    }

    private static function readLongProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        string $className,
        string $propName
    ): Value {
        $prop = $object->propertyFetch($objPtr, $className, $propName);
        if (JITVariable::TYPE_NATIVE_LONG === $prop->type) {
            return $context->builder->load($prop->value);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $prop->value
        );
    }

    private static function readDoubleProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        string $className,
        string $propName
    ): Value {
        $prop = $object->propertyFetch($objPtr, $className, $propName);
        if (JITVariable::TYPE_NATIVE_DOUBLE === $prop->type) {
            return $context->builder->load($prop->value);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $prop->value
        );
    }

    private static function writeLongProp(
        Context $context,
        ObjectBuiltin $object,
        Value $objPtr,
        string $className,
        string $propName,
        Value $value
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $object->propertyStore(
            $object->propertySlotFor($objPtr, $className, $propName),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $value
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
    }

    private static function stringData(Context $context, Value $strPtr): Value
    {
        $off = $context->structFieldIndex($strPtr, 'value');

        return $context->builder->pointerCast(
            $context->builder->structGep($strPtr, $off),
            $context->getTypeFromString('int8*')
        );
    }

    private static function emitTypeErrorAndAbort(Context $context, string $message): void
    {
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function typeLabel(int $type): string
    {
        return match ($type) {
            JITVariable::TYPE_NATIVE_LONG => 'int',
            JITVariable::TYPE_NATIVE_DOUBLE => 'float',
            JITVariable::TYPE_NATIVE_BOOL => 'bool',
            JITVariable::TYPE_STRING => 'string',
            JITVariable::TYPE_NULL => 'null',
            default => 'mixed',
        };
    }
}
