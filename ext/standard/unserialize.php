<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPCompiler\VM\NativeDateMalformedStringException;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * unserialize() — scalar/array via VmUnserializeFormat; objects via VmSerialize (JIT/AOT: __compiler_unserialize).
 */
final class unserialize extends Internal
{
    public function __construct()
    {
        parent::__construct('unserialize');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/var.c — ArgumentCountError (#28474).
        $this->requireArgCountRange($frame, 'unserialize', 1, 2);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }
        // Soft-null DEP+coerce on 8.4 outside strict_types — Zend Z_PARAM_STR (#21223; #29765 strict edge).
        $payload = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'unserialize',
            0,
            'data'
        );
        $options = null;
        if ($argc > 1) {
            $optionsVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $optionsVar->type) {
                // php-src ext/standard/var.c — Z_PARAM_ARRAY for options (#24149).
                throw new \TypeError(
                    'unserialize(): Argument #2 ($options) must be of type array, '
                    .EnumCaseSupport::typeNameForVariable($optionsVar).' given'
                );
            }
            $options = self::extractUnserializeOptions($optionsVar);
        }
        $decoded = VmSerialize::unserializePayload(
            $frame->vmContext,
            $payload,
            $options,
            $frame
        );
        if (false === $decoded) {
            self::emitParseFailureNotice($frame, $payload, $options);
            $frame->returnVar->bool(false);

            return;
        }
        if ($decoded instanceof Variable) {
            $frame->returnVar->copyFrom($decoded);

            return;
        }
        $frame->returnVar->copyFrom(VmJson::import($decoded));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'unserialize', 1, 2)) {
            $nullSlot = JitValueBox::alloc($context);
            $nullPtr = JitValueBox::pointer($context, $nullSlot);
            $context->builder->call($context->lookupFunction('__value__writeNull'), $nullPtr);

            return $nullPtr;
        }
        $options = null;
        if (\count($args) > 1) {
            $options = JitUnserializeOptions::tryCompileTime(
                $context,
                $args[1],
                $context->jitEnclosingBlock,
                $context->jitUnserializeOptionsOperand
            );
            if (null === $options) {
                throw new \LogicException('unserialize() runtime options not supported in this compiler build');
            }
        }

        $compileTime = self::compileTimeUnserialize($context, $args[0], $options);
        if (null !== $compileTime) {
            return $compileTime;
        }

        if (null !== $options) {
            return JitUnserialize::decodeRuntimeWithOptions($context, $args[0], $options);
        }

        return JitUnserialize::decodeRuntime($context, $args[0]);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    private static function compileTimeUnserialize(Context $context, JITVariable $arg, ?array $options = null): ?Value
    {
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            if ($context->callerStrictTypes) {
                $message = 'unserialize(): Argument #1 ($data) must be of type string, null given';
                if (null !== TryCatchHelper::resolveThrowHandler($context)) {
                    TryCatchHelper::emitCatchableClassError($context, 'TypeError', $message);

                    return JitJsonDecode::materializeScalar($context, false);
                }

                return null;
            }
            // Soft-null: empty payload → false (same as unserialize('')) (#21223).
            JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'unserialize', 0, 'data');

            return $context->helper->loadValue(
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt(0, false)
                )
            );
        }
        if (JITVariable::TYPE_STRING !== $arg->type) {
            // Assign of serialize() often yields a VALUE box that still carries the
            // folded Zend wire on compileTimeString (#34594 / peer #34576).
            if (null === ($arg->compileTimeString ?? null)
                || JITVariable::TYPE_OBJECT === $arg->type) {
                return null;
            }
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null === $literal) {
            return null;
        }
        // DatePeriod before DateTime — nested start O:DateTime must not win (#34608 / peer #34591).
        $periodObj = self::tryMaterializeDatePeriodWire($context, $literal);
        if (null !== $periodObj) {
            return $periodObj;
        }
        // DateTime / DateTimeImmutable Zend wire — allocate + stamp __dt_* (#34576 / re-#10710).
        // Also publish lastDateTimeUnserializeInstant for format()/getOffset() (#34614 / #33939).
        $dateObj = self::tryMaterializeDateTimeWire($context, $literal);
        if (null !== $dateObj) {
            return $dateObj;
        }
        // DateInterval / DateTimeZone — same fold path; NestedJIT firstIntProp→slot0
        // SIGSEGVs / empties zone name (#34599 / peer #34594 / #34584).
        $intervalObj = self::tryMaterializeDateIntervalWire($context, $literal);
        if (null !== $intervalObj) {
            return $intervalObj;
        }
        $zoneObj = self::tryMaterializeDateTimeZoneWire($context, $literal);
        if (null !== $zoneObj) {
            return $zoneObj;
        }
        // Object/enum/class wires need runtime materialize (ArrayObject bag #33636) —
        // decodePayload returns ObjectEntry which cannot fold into LLVM scalars.
        if (\preg_match('/(?:^|[{;])[OCE]:/', $literal)) {
            return null;
        }
        $decoded = VmUnserializeFormat::decodePayload($literal, $options);
        if (false === $decoded) {
            return $context->helper->loadValue(
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $context->getTypeFromString('int1')->constInt(0, false)
                )
            );
        }
        if (null === $decoded) {
            return JitJsonDecode::materializeNull($context);
        }
        if (\is_bool($decoded)) {
            return JitJsonDecode::materializeScalar($context, $decoded);
        }
        if (\is_int($decoded)) {
            return JitJsonDecode::materializeScalar($context, $decoded);
        }
        if (\is_string($decoded)) {
            return JitJsonDecode::materializeScalar($context, $decoded);
        }
        if (\is_array($decoded)) {
            return JitJsonDecode::materializeArray($context, $decoded);
        }

        // Non-scalar fold result — defer to runtime (do not throw; peer O: path #33636).
        return null;
    }

    /**
     * Fold Zend DatePeriod serialize wire into a live object + foreach snapshot (#34608).
     *
     * Peer DateInterval/DateTime folds (#34599 / #34576). Uses Object_::lookup() so
     * literal-only scripts (no prior `new DatePeriod`) still fold (#34611).
     * NestedJIT DatePeriod bag remains TBD for true runtime payloads (file_get_contents).
     * php-src: php_date_period_initialize_from_hash.
     */
    private static function tryMaterializeDatePeriodWire(Context $context, string $literal): ?Value
    {
        if (!\preg_match('/^O:\d+:"DatePeriod":(\d+):\{(.*)\}$/s', $literal, $m)) {
            return null;
        }
        $bag = $m[2];
        if (!\preg_match(
            '/s:5:"start";O:\d+:"(DateTime(?:Immutable)?)":3:\{'
            .'s:4:"date";s:\d+:"([^"]*)";s:13:"timezone_type";i:\d+;s:8:"timezone";s:\d+:"([^"]*)";\}/',
            $bag,
            $startM
        )) {
            return null;
        }
        $startClass = $startM[1];
        $startDateWire = $startM[2];
        $startTz = $startM[3];
        $endClass = null;
        $endDateWire = null;
        $endTz = null;
        $hasEnd = false;
        if (\preg_match('/s:3:"end";N;/', $bag)) {
            $hasEnd = false;
        } elseif (\preg_match(
            '/s:3:"end";O:\d+:"(DateTime(?:Immutable)?)":3:\{'
            .'s:4:"date";s:\d+:"([^"]*)";s:13:"timezone_type";i:\d+;s:8:"timezone";s:\d+:"([^"]*)";\}/',
            $bag,
            $endM
        )) {
            $hasEnd = true;
            $endClass = $endM[1];
            $endDateWire = $endM[2];
            $endTz = $endM[3];
        } else {
            return null;
        }
        if (!\preg_match(
            '/s:8:"interval";O:\d+:"DateInterval":\d+:\{(.*)\}s:11:"recurrences";/s',
            $bag,
            $intM
        )) {
            return null;
        }
        $intervalBag = $intM[1];
        $includeStart = 1 === \preg_match('/s:18:"include_start_date";b:1;/', $bag);
        $includeEnd = 1 === \preg_match('/s:16:"include_end_date";b:1;/', $bag);
        try {
            VmDateTimeNative::validateTimezoneId($startTz);
            $startParsed = VmDateTimeNative::parseDateTime($startDateWire, $startTz);
        } catch (NativeDateInvalidTimeZoneException|NativeDateMalformedStringException) {
            return null;
        }
        $startTs = (int) $startParsed['timestamp'];
        $startTzName = \is_string($startParsed['timezone'] ?? null) ? $startParsed['timezone'] : $startTz;
        $endTs = null;
        if ($hasEnd) {
            try {
                VmDateTimeNative::validateTimezoneId((string) $endTz);
                $endParsed = VmDateTimeNative::parseDateTime((string) $endDateWire, (string) $endTz);
            } catch (NativeDateInvalidTimeZoneException|NativeDateMalformedStringException) {
                return null;
            }
            $endTs = (int) $endParsed['timestamp'];
        }
        $delta = ((int) (self::wireBagInt($intervalBag, 'd') ?? 0)) * 86400
            + ((int) (self::wireBagInt($intervalBag, 'h') ?? 0)) * 3600
            + ((int) (self::wireBagInt($intervalBag, 'i') ?? 0)) * 60
            + ((int) (self::wireBagInt($intervalBag, 's') ?? 0));
        if (0 !== (int) (self::wireBagInt($intervalBag, 'invert') ?? 0)) {
            $delta = -$delta;
        }
        if (0 === $delta && null !== $endTs) {
            return null;
        }
        $timestamps = [];
        if (null !== $endTs) {
            $t = $startTs;
            if (!$includeStart) {
                $t += $delta;
            }
            $guard = 0;
            while ($guard < 100000) {
                ++$guard;
                if ($includeEnd) {
                    if ($t > $endTs) {
                        break;
                    }
                } elseif ($t >= $endTs) {
                    break;
                }
                $timestamps[] = $t;
                $t += $delta;
            }
        } else {
            // Recurrence form (end is null). Wire stores the public `recurrences` property,
            // which already equals foreach count (ctor arg + 1 when include_start — #26852).
            // Do not add +1 again (#34626 / re-#34608).
            if (!\preg_match('/s:11:"recurrences";i:(\d+);/', $bag, $recM)) {
                return null;
            }
            $wireRecurrences = (int) $recM[1];
            if ($wireRecurrences < 1 || 0 === $delta) {
                return null;
            }
            $t = $startTs;
            if (!$includeStart) {
                $t += $delta;
            }
            for ($i = 0; $i < $wireRecurrences; ++$i) {
                $timestamps[] = $t;
                $t += $delta;
            }
        }

        $className = 'DatePeriod';
        $objectType = $context->type->object;
        // lookup() registerExternalClass seeds DatePeriod props — classIdByName is null
        // when the unit never `new DatePeriod` (literal-only unserialize, #34611 / re-#34608).
        $classId = $objectType->classIdByName($className)
            ?? $objectType->classIdForLowerName('dateperiod')
            ?? $objectType->lookup($className);
        $period = $objectType->allocate($classId);
        ReflectionSetup::markConstructed($context, $period);

        $startWire = 'O:'.\strlen($startClass).':"'.$startClass.'":3:{'
            .'s:4:"date";s:'.\strlen($startDateWire).':"'.$startDateWire.'";'
            .'s:13:"timezone_type";i:3;'
            .'s:8:"timezone";s:'.\strlen($startTz).':"'.$startTz.'";}';
        $startVal = self::tryMaterializeDateTimeWire($context, $startWire);
        if (null === $startVal) {
            return null;
        }
        $startObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $startVal
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($period, $className, 'start'),
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $startObj),
            JITVariable::TYPE_OBJECT
        );

        // current is null on fresh unserialize.
        $nullObj = $context->getTypeFromString('__object__*')->constNull();
        $objectType->propertyStore(
            $objectType->propertySlotFor($period, $className, 'current'),
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $nullObj),
            JITVariable::TYPE_OBJECT
        );

        if ($hasEnd) {
            $endWire = 'O:'.\strlen((string) $endClass).':"'.$endClass.'":3:{'
                .'s:4:"date";s:'.\strlen((string) $endDateWire).':"'.$endDateWire.'";'
                .'s:13:"timezone_type";i:3;'
                .'s:8:"timezone";s:'.\strlen((string) $endTz).':"'.$endTz.'";}';
            $endVal = self::tryMaterializeDateTimeWire($context, $endWire);
            if (null === $endVal) {
                return null;
            }
            $endObj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $endVal
            );
            $objectType->propertyStore(
                $objectType->propertySlotFor($period, $className, 'end'),
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $endObj),
                JITVariable::TYPE_OBJECT
            );
        } else {
            $objectType->propertyStore(
                $objectType->propertySlotFor($period, $className, 'end'),
                new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $nullObj),
                JITVariable::TYPE_OBJECT
            );
        }

        $intervalState = [
            'y' => self::wireBagInt($intervalBag, 'y') ?? 0,
            'm' => self::wireBagInt($intervalBag, 'm') ?? 0,
            'd' => self::wireBagInt($intervalBag, 'd') ?? 0,
            'h' => self::wireBagInt($intervalBag, 'h') ?? 0,
            'i' => self::wireBagInt($intervalBag, 'i') ?? 0,
            's' => self::wireBagInt($intervalBag, 's') ?? 0,
            'f' => self::wireBagFloat($intervalBag, 'f') ?? 0.0,
            'invert' => self::wireBagInt($intervalBag, 'invert') ?? 0,
            'days' => self::wireBagDays($intervalBag),
            'from_string' => false,
        ];
        $intervalVal = self::allocateDateIntervalFromState($context, $intervalState);
        if (null === $intervalVal) {
            return null;
        }
        // allocateDateIntervalFromState publishes lastDateIntervalDiffState — clear so
        // unserialize sync does not stamp DateInterval onto the DatePeriod result (#34608).
        $context->lastDateIntervalDiffState = null;
        $intervalObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $intervalVal
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($period, $className, 'interval'),
            new JITVariable($context, JITVariable::TYPE_OBJECT, JITVariable::KIND_VALUE, $intervalObj),
            JITVariable::TYPE_OBJECT
        );

        $i64 = $context->getTypeFromString('int64');
        $recurrences = 1;
        if (\preg_match('/s:11:"recurrences";i:(\d+);/', $bag, $recM2)) {
            $recurrences = (int) $recM2[1];
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($period, $className, 'recurrences'),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt($recurrences, false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $i1 = $context->getTypeFromString('int1');
        foreach (['include_start_date' => $includeStart, 'include_end_date' => $includeEnd] as $prop => $flag) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($period, $className, $prop),
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_BOOL,
                    JITVariable::KIND_VALUE,
                    $i1->constInt($flag ? 1 : 0, false)
                ),
                JITVariable::TYPE_NATIVE_BOOL
            );
        }

        $context->lastDatePeriodUnserializeTimestamps = $timestamps;
        $context->lastDatePeriodUnserializeTimezone = $startTzName;
        $context->lastUnserializeObjectClassUserType = 'DatePeriod';
        // Nested start/end tryMaterializeDateTimeWire published DateTime stamps — do not
        // let syncDateTimeUnserializeMetaToResult stamp them onto the DatePeriod local (#34614).
        $context->lastDateTimeUnserializeInstant = null;

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $period
        );

        return $ptr;
    }

    /**
     * Fold Zend DateTime / DateTimeImmutable serialize wire into a live object (#34576).
     *
     * php-src: ext/date/php_date.c — php_date_unserialize / DateTime::__unserialize
     */
    private static function tryMaterializeDateTimeWire(Context $context, string $literal): ?Value
    {
        if (!\preg_match(
            '/^O:\d+:"(DateTime(?:Immutable)?)":3:\{(.*)\}$/s',
            $literal,
            $m
        )) {
            return null;
        }
        $className = $m[1];
        $bag = $m[2];
        if (!\preg_match(
            '/s:4:"date";s:\d+:"([^"]*)";s:13:"timezone_type";i:(\d+);s:8:"timezone";s:\d+:"([^"]*)";/',
            $bag,
            $p
        )) {
            return null;
        }
        $dateWire = $p[1];
        $timezone = $p[3];
        try {
            VmDateTimeNative::validateTimezoneId($timezone);
            $parsed = VmDateTimeNative::parseDateTime($dateWire, $timezone);
        } catch (NativeDateInvalidTimeZoneException|NativeDateMalformedStringException) {
            return null;
        }
        $tzName = \is_string($parsed['timezone'] ?? null) ? $parsed['timezone'] : $timezone;
        $objectType = $context->type->object;
        // Peer DatePeriod fold — literal DateTime wire without prior `new` (#34611).
        $classId = $objectType->classIdByName($className)
            ?? $objectType->classIdForLowerName(strtolower($className))
            ?? $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        ReflectionSetup::markConstructed($context, $obj);
        $i64 = $context->getTypeFromString('int64');
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TS_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt((int) $parsed['timestamp'], false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::MICROSECOND_PROPERTY),
            new JITVariable(
                $context,
                JITVariable::TYPE_NATIVE_LONG,
                JITVariable::KIND_VALUE,
                $i64->constInt((int) ($parsed['microsecond'] ?? 0), false)
            ),
            JITVariable::TYPE_NATIVE_LONG
        );
        $tzVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($tzName))
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TZ_PROPERTY),
            $tzVar,
            JITVariable::TYPE_STRING
        );
        // Publish construct-style stamps so format('c')/getOffset() do not fall into the
        // UTC civil bake / offset=0 runtime path (#34614 / peer #33939).
        $context->lastDateTimeUnserializeInstant = [
            'timestamp' => (int) $parsed['timestamp'],
            'microsecond' => (int) ($parsed['microsecond'] ?? 0),
            'timezone' => $tzName,
            'className' => $className,
        ];
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    /**
     * Fold Zend DateInterval serialize wire into a live object (#34599 / peer #34584).
     *
     * php-src: ext/date/php_date.c — php_date_interval_initialize_from_hash /
     * DateInterval::__unserialize
     */
    private static function tryMaterializeDateIntervalWire(Context $context, string $literal): ?Value
    {
        if (!\preg_match('/^O:\d+:"DateInterval":(\d+):\{(.*)\}$/s', $literal, $m)) {
            return null;
        }
        $bag = $m[2];
        // from_string + date_string wire (createFromDateString).
        if (\preg_match(
            '/s:11:"from_string";b:1;s:11:"date_string";s:\d+:"([^"]*)";/',
            $bag,
            $fs
        )) {
            $warning = null;
            $parsed = VmDateInterval::parseFromDateString($fs[1], $warning);
            if (null === $parsed) {
                return null;
            }
            $parsed['days'] = false;
            $parsed['from_string'] = true;
            $parsed['date_string'] = $fs[1];

            return self::allocateDateIntervalFromState($context, $parsed);
        }
        $state = [
            'y' => self::wireBagInt($bag, 'y') ?? 0,
            'm' => self::wireBagInt($bag, 'm') ?? 0,
            'd' => self::wireBagInt($bag, 'd') ?? 0,
            'h' => self::wireBagInt($bag, 'h') ?? 0,
            'i' => self::wireBagInt($bag, 'i') ?? 0,
            's' => self::wireBagInt($bag, 's') ?? 0,
            'f' => self::wireBagFloat($bag, 'f') ?? 0.0,
            'invert' => self::wireBagInt($bag, 'invert') ?? 0,
            'days' => self::wireBagDays($bag),
            'from_string' => false,
        ];

        return self::allocateDateIntervalFromState($context, $state);
    }

    /**
     * @param array{
     *     y: int, m: int, d: int, h: int, i: int, s: int, f: float,
     *     invert: int, days: bool|int, from_string?: bool, date_string?: string
     * } $state
     */
    private static function allocateDateIntervalFromState(Context $context, array $state): ?Value
    {
        $className = 'DateInterval';
        $objectType = $context->type->object;
        // Nested DateInterval inside DatePeriod literal wire (#34611).
        $classId = $objectType->classIdByName($className)
            ?? $objectType->classIdForLowerName('dateinterval')
            ?? $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $i64 = $context->getTypeFromString('int64');
        foreach (['y', 'm', 'd', 'h', 'i', 's', 'invert'] as $name) {
            $objectType->propertyStore(
                $objectType->propertySlotFor($obj, $className, $name),
                new JITVariable(
                    $context,
                    JITVariable::TYPE_NATIVE_LONG,
                    JITVariable::KIND_VALUE,
                    $i64->constInt((int) $state[$name], false)
                ),
                JITVariable::TYPE_NATIVE_LONG
            );
        }
        $fSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            JitValueBox::pointer($context, $fSlot),
            $context->constantFromFloat((float) $state['f'])
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, 'f'),
            new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $fSlot),
            JITVariable::TYPE_VALUE
        );
        $daysSlot = JitValueBox::alloc($context);
        if (\is_int($state['days'])) {
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                JitValueBox::pointer($context, $daysSlot),
                $i64->constInt($state['days'], false)
            );
        } else {
            JitValueBox::writeBool(
                $context,
                $daysSlot,
                $context->getTypeFromString('int32')->constInt(!empty($state['days']) ? 1 : 0, false)
            );
        }
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, 'days'),
            new JITVariable($context, JITVariable::TYPE_VALUE, JITVariable::KIND_VARIABLE, $daysSlot),
            JITVariable::TYPE_VALUE
        );
        // JIT Object_ layout has no __di_from_string / __di_date_string slots
        // (peer JitDateIntervalConstruct) — public y..days are enough for format().
        ReflectionSetup::markConstructed($context, $obj);
        // Publish stamp so DateInterval::format() can bake (#34599 / peer #33912 diff).
        // Runtime NestedJIT formatFromScalars still SIGSEGVs on float args; fold avoids it.
        $context->lastDateIntervalDiffState = [
            'y' => (int) $state['y'],
            'm' => (int) $state['m'],
            'd' => (int) $state['d'],
            'h' => (int) $state['h'],
            'i' => (int) $state['i'],
            's' => (int) $state['s'],
            'f' => (float) $state['f'],
            'invert' => (int) $state['invert'],
            'days' => $state['days'],
        ];
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }
    private static function tryMaterializeDateTimeZoneWire(Context $context, string $literal): ?Value
    {
        if (!\preg_match(
            '/^O:\d+:"DateTimeZone":2:\{(.*)\}$/s',
            $literal,
            $m
        )) {
            return null;
        }
        $bag = $m[1];
        if (!\preg_match(
            '/s:13:"timezone_type";i:(\d+);s:8:"timezone";s:\d+:"([^"]*)";/',
            $bag,
            $p
        )) {
            return null;
        }
        $timezoneType = (int) $p[1];
        $timezone = $p[2];
        if ($timezoneType < 1 || $timezoneType > 3 || str_contains($timezone, "\0")) {
            return null;
        }
        try {
            VmDateTimeNative::validateTimezoneId($timezone);
        } catch (NativeDateInvalidTimeZoneException) {
            return null;
        }
        $className = 'DateTimeZone';
        $objectType = $context->type->object;
        // Literal DateTimeZone wire without prior `new` (#34611).
        $classId = $objectType->classIdByName($className)
            ?? $objectType->classIdForLowerName('datetimezone')
            ?? $objectType->lookup($className);
        $obj = $objectType->allocate($classId);
        $tzVar = new JITVariable(
            $context,
            JITVariable::TYPE_STRING,
            JITVariable::KIND_VALUE,
            $context->builder->load($context->constantStringFromString($timezone))
        );
        $objectType->propertyStore(
            $objectType->propertySlotFor($obj, $className, DateTimeSupport::TZ_NAME_PROPERTY),
            $tzVar,
            JITVariable::TYPE_STRING
        );
        ReflectionSetup::markConstructed($context, $obj);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }

    private static function wireBagInt(string $bag, string $name): ?int
    {
        $len = \strlen($name);
        if (!\preg_match('/s:'.$len.':"'.\preg_quote($name, '/').'";i:(-?\d+);/', $bag, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    private static function wireBagFloat(string $bag, string $name): ?float
    {
        $len = \strlen($name);
        if (!\preg_match(
            '/s:'.$len.':"'.\preg_quote($name, '/').'";d:(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?);/',
            $bag,
            $m
        )) {
            return null;
        }

        return (float) $m[1];
    }

    /** @return bool|int */
    private static function wireBagDays(string $bag): bool|int
    {
        if (\preg_match('/s:4:"days";i:(-?\d+);/', $bag, $m)) {
            return (int) $m[1];
        }
        if (\preg_match('/s:4:"days";b:([01]);/', $bag, $m)) {
            return 1 === (int) $m[1];
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function parseUnserializeOptionsArray(Variable $optionsVar): array
    {
        $options = [];
        foreach ($optionsVar->toArray()->iterateKeyed(true) as [$keyVar, $value]) {
            $keyVar = $keyVar->resolveIndirect();
            $key = Variable::TYPE_STRING === $keyVar->type
                ? $keyVar->toString()
                : (string) $keyVar->toInt();
            $resolved = $value->resolveIndirect();
            if ('allowed_classes' === $key) {
                if (Variable::TYPE_BOOLEAN === $resolved->type) {
                    $options['allowed_classes'] = $resolved->toBool();
                } elseif (Variable::TYPE_ARRAY === $resolved->type) {
                    $allowed = [];
                    foreach ($resolved->toArray()->iterate(true) as $entry) {
                        $entry = $entry->resolveIndirect();
                        if (Variable::TYPE_STRING === $entry->type) {
                            $allowed[] = $entry->toString();
                        }
                    }
                    $options['allowed_classes'] = $allowed;
                } else {
                    // php-src ext/standard/var.c — php_var_unserialize_with_options (#24149).
                    throw new \TypeError(self::allowedClassesOptionTypeErrorMessage($resolved));
                }
                continue;
            }
            if ('max_depth' === $key) {
                if (Variable::TYPE_INTEGER !== $resolved->type) {
                    // php-src ext/standard/var.c — Option "max_depth" must be int (#24149).
                    throw new \TypeError(
                        'unserialize(): Option "max_depth" must be of type int, '
                        .EnumCaseSupport::typeNameForVariable($resolved).' given'
                    );
                }
                $options['max_depth'] = $resolved->toInt();
                continue;
            }
            throw new \LogicException(
                'unserialize() option '.$key.' not supported in this compiler build'
            );
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractUnserializeOptions(Variable $optionsVar): array
    {
        return self::parseUnserializeOptionsArray($optionsVar);
    }

    /**
     * Zend message for wrong-type allowed_classes (php-src ext/standard/var.c; #24149).
     */
    public static function allowedClassesOptionTypeErrorMessage(Variable $value): string
    {
        return 'unserialize(): Option "allowed_classes" must be of type array|bool, '
            .EnumCaseSupport::typeNameForVariable($value).' given';
    }

    /**
     * Zend message for wrong-type allowed_classes from a native PHP value (#24149).
     */
    public static function allowedClassesOptionTypeErrorMessageFromMixed(mixed $value): string
    {
        return 'unserialize(): Option "allowed_classes" must be of type array|bool, '
            .self::zendMixedTypeName($value).' given';
    }

    private static function zendMixedTypeName(mixed $value): string
    {
        if (\is_object($value)) {
            return $value::class;
        }
        if (null === $value) {
            return 'null';
        }
        if (\is_bool($value)) {
            return 'bool';
        }
        if (\is_int($value)) {
            return 'int';
        }
        if (\is_float($value)) {
            return 'float';
        }
        if (\is_string($value)) {
            return 'string';
        }
        if (\is_array($value)) {
            return 'array';
        }
        if (\is_resource($value)) {
            return 'resource';
        }

        return 'mixed';
    }

    /** php-src var_unserializer — E_NOTICE through 8.2; E_WARNING from 8.3 (#13715, #9206, #29204). */
    private static function emitParseFailureNotice(Frame $frame, string $payload, ?array $options = null): void
    {
        if (null === $frame->vmContext) {
            return;
        }
        $depthLimit = VmUnserializeFormat::lastMaxDepthExceeded();
        if (null !== $depthLimit) {
            $frame->vmContext->errors->triggerError(
                \sprintf(
                    'unserialize(): Maximum depth of %d exceeded. The depth limit can be changed using the max_depth unserialize() option or the unserialize_max_depth ini setting',
                    $depthLimit
                ),
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
        }
        // php-src ext/standard/var.c — empty buffer → false with no Error-at-offset (#29483).
        if ('' === $payload) {
            return;
        }
        $offset = VmUnserializeFormat::lastErrorOffset();
        $length = VmUnserializeFormat::lastPayloadLength();
        if (null === $offset || null === $length) {
            // Paths that return false without parser state — treat as EOF failure (#29204).
            $length = \strlen($payload);
            $offset = $length;
        }
        $level = \PHPCompiler\CompilerVersion::supportsUnserializeErrorAtOffsetWarning()
            ? ErrorReporter::E_WARNING
            : ErrorReporter::E_NOTICE;
        $frame->vmContext->errors->triggerError(
            \sprintf('unserialize(): Error at offset %d of %d bytes', $offset, $length),
            $level,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }
}
