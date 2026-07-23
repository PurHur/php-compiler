<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmJson;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * DateTime*::__serialize / __unserialize / __wakeup — php-src ext/date/php_date.c (#22596).
 *
 * Expose Zend method-table surface; reuse existing Zend wire helpers for payloads.
 */
final class DateSerializeMethods
{
    public static function registerOnDateTimeLike(
        ClassEntry $entry,
        string $classKey,
        string $classLabel,
        int $pub
    ): void {
        $entry->methods['__serialize'] = new DateTimeLikeSerialize($classKey, $classLabel);
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methods['__unserialize'] = new DateTimeLikeUnserialize($classKey, $classLabel);
        $entry->methodVisibility['__unserialize'] = $pub;
        $entry->methodNames['__unserialize'] = '__unserialize';
        $entry->methods['__wakeup'] = new DateTimeLikeWakeup($classKey, $classLabel);
        $entry->methodVisibility['__wakeup'] = $pub;
        $entry->methodNames['__wakeup'] = '__wakeup';
    }

    public static function registerOnDateTimeZone(ClassEntry $entry, int $pub): void
    {
        $entry->methods['__serialize'] = new DateTimeZoneSerialize();
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methods['__unserialize'] = new DateTimeZoneUnserialize();
        $entry->methodVisibility['__unserialize'] = $pub;
        $entry->methodNames['__unserialize'] = '__unserialize';
        $entry->methods['__wakeup'] = new DateTimeZoneWakeup();
        $entry->methodVisibility['__wakeup'] = $pub;
        $entry->methodNames['__wakeup'] = '__wakeup';
    }

    public static function registerOnDateInterval(ClassEntry $entry, int $pub): void
    {
        $entry->methods['__serialize'] = new DateIntervalSerialize();
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methods['__unserialize'] = new DateIntervalUnserialize();
        $entry->methodVisibility['__unserialize'] = $pub;
        $entry->methodNames['__unserialize'] = '__unserialize';
        $entry->methods['__wakeup'] = new DateIntervalWakeup();
        $entry->methodVisibility['__wakeup'] = $pub;
        $entry->methodNames['__wakeup'] = '__wakeup';
    }

    public static function registerOnDatePeriod(ClassEntry $entry, int $pub): void
    {
        $entry->methods['__serialize'] = new DatePeriodSerialize();
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methodNames['__serialize'] = '__serialize';
        $entry->methods['__unserialize'] = new DatePeriodUnserialize();
        $entry->methodVisibility['__unserialize'] = $pub;
        $entry->methodNames['__unserialize'] = '__unserialize';
        $entry->methods['__wakeup'] = new DatePeriodWakeup();
        $entry->methodVisibility['__wakeup'] = $pub;
        $entry->methodNames['__wakeup'] = '__wakeup';
    }

    /**
     * @param array<string, Variable> $map
     */
    public static function propertyMapToArrayVariable(array $map): Variable
    {
        $ht = new HashTable();
        foreach ($map as $key => $value) {
            $ht->add((string) $key, $value);
        }
        $out = new Variable(Variable::TYPE_ARRAY);
        $out->array($ht);

        return $out;
    }

    public static function objectPropertyBag(ObjectEntry $obj): Variable
    {
        $ht = new HashTable();
        foreach ($obj->propertiesWithNames() as $name => $var) {
            if (DateTimeSupport::isInternalStorageProperty((string) $name)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($var->resolveIndirect());
            $ht->add((string) $name, $copy);
        }
        $out = new Variable(Variable::TYPE_ARRAY);
        $out->array($ht);

        return $out;
    }
}

/** php-src DateTime/DateTimeImmutable::__serialize (#22596). */
final class DateTimeLikeSerialize extends VmClassMethod
{
    public function __construct(
        private readonly string $classKey,
        private readonly string $classLabel,
    ) {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                $this->classLabel.'::__serialize() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            $this->classLabel.'::__serialize()'
        );
        DateTimeSupport::requireInitializedForSerialize($obj, $this->classLabel);
        $bag = DateSerializeMethods::propertyMapToArrayVariable(
            DateTimeSupport::varExportPropertyMap($obj)
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($bag): void {
            $ret->copyFrom($bag);
        });
    }
}

/** php-src DateTime/DateTimeImmutable::__unserialize (#22596). */
final class DateTimeLikeUnserialize extends VmClassMethod
{
    public function __construct(
        private readonly string $classKey,
        private readonly string $classLabel,
    ) {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException($this->classLabel.'::__unserialize() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                $this->classLabel.'::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            $this->classLabel.'::__unserialize()'
        );
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                $this->classLabel.'::__unserialize(): Argument #1 ($data) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arg).' given'
            );
        }
        $data = VmJson::export($arg);
        if (!\is_array($data)) {
            throw new \Error('Invalid serialization data for '.$this->classLabel.' object');
        }
        DateTimeSupport::restoreFromZendSerialize(
            $frame->vmContext,
            $this->classKey,
            $data,
            $obj
        );
    }
}

/** php-src DateTime/DateTimeImmutable::__wakeup (#22596). */
final class DateTimeLikeWakeup extends VmClassMethod
{
    public function __construct(
        private readonly string $classKey,
        private readonly string $classLabel,
    ) {
        parent::__construct('__wakeup');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException($this->classLabel.'::__wakeup() requires VM context');
        }
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                $this->classLabel.'::__wakeup() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateTimeSupport::requireDateTimeLike(
            $frame->calledArgs[0],
            $this->classLabel.'::__wakeup()'
        );
        $bag = DateSerializeMethods::objectPropertyBag($obj);
        $data = VmJson::export($bag);
        if (!\is_array($data)) {
            throw new \Error('Invalid serialization data for '.$this->classLabel.' object');
        }
        DateTimeSupport::restoreFromZendSerialize(
            $frame->vmContext,
            $this->classKey,
            $data,
            $obj
        );
    }
}

/** php-src DateTimeZone::__serialize (#22596). */
final class DateTimeZoneSerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'DateTimeZone::__serialize() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'DateTimeZone::__serialize()'
        );
        DateTimeSupport::requireInitializedForSerialize($obj, 'DateTimeZone');
        $bag = DateSerializeMethods::propertyMapToArrayVariable(
            DateTimeSupport::varExportTimezonePropertyMap($obj)
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($bag): void {
            $ret->copyFrom($bag);
        });
    }
}

/** php-src DateTimeZone::__unserialize (#22596). */
final class DateTimeZoneUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTimeZone::__unserialize() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'DateTimeZone::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'DateTimeZone::__unserialize()'
        );
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'DateTimeZone::__unserialize(): Argument #1 ($data) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arg).' given'
            );
        }
        $data = VmJson::export($arg);
        if (!\is_array($data)) {
            throw new \Error('Invalid serialization data for DateTimeZone object');
        }
        DateTimeSupport::restoreTimezoneFromZendSerialize($frame->vmContext, $data, $obj);
    }
}

/** php-src DateTimeZone::__wakeup (#22596). */
final class DateTimeZoneWakeup extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__wakeup');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateTimeZone::__wakeup() requires VM context');
        }
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'DateTimeZone::__wakeup() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'DateTimeZone::__wakeup()'
        );
        $bag = DateSerializeMethods::objectPropertyBag($obj);
        $data = VmJson::export($bag);
        if (!\is_array($data)) {
            throw new \Error('Timezone initialization failed');
        }
        DateTimeSupport::restoreTimezoneFromZendSerialize(
            $frame->vmContext,
            $data,
            $obj,
            true
        );
    }
}

/** php-src DateInterval::__serialize (#22596). */
final class DateIntervalSerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'DateInterval::__serialize() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[0],
            'DateInterval::__serialize()'
        );
        DateIntervalSupport::requireInitializedForSerialize($obj);
        $bag = DateSerializeMethods::propertyMapToArrayVariable(
            DateIntervalSupport::varExportPropertyMap($obj)
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($bag): void {
            $ret->copyFrom($bag);
        });
    }
}

/** php-src DateInterval::__unserialize (#22596). */
final class DateIntervalUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateInterval::__unserialize() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'DateInterval::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[0],
            'DateInterval::__unserialize()'
        );
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'DateInterval::__unserialize(): Argument #1 ($data) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arg).' given'
            );
        }
        $data = VmJson::export($arg);
        if (!\is_array($data)) {
            $data = [];
        }
        DateIntervalSupport::restoreFromZendSerialize($frame->vmContext, $data, $obj);
    }
}

/** php-src DateInterval::__wakeup (#22596). */
final class DateIntervalWakeup extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__wakeup');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateInterval::__wakeup() requires VM context');
        }
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'DateInterval::__wakeup() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $obj = DateIntervalSupport::requireDateInterval(
            $frame->calledArgs[0],
            'DateInterval::__wakeup()'
        );
        $bag = DateSerializeMethods::objectPropertyBag($obj);
        $data = VmJson::export($bag);
        if (!\is_array($data)) {
            $data = [];
        }
        DateIntervalSupport::restoreFromZendSerialize($frame->vmContext, $data, $obj);
    }
}

/** php-src DatePeriod::__serialize (#22596). */
final class DatePeriodSerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'DatePeriod::__serialize() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $recv = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $recv->type) {
            throw new \TypeError('DatePeriod::__serialize() must be called on an object');
        }
        $obj = $recv->toObject();
        DatePeriodSupport::requireInitializedForSerialize($obj);
        $bag = DateSerializeMethods::propertyMapToArrayVariable(
            DatePeriodSupport::varExportPropertyMap($obj)
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($bag): void {
            $ret->copyFrom($bag);
        });
    }
}

/** php-src DatePeriod::__unserialize (#22596). */
final class DatePeriodUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DatePeriod::__unserialize() requires VM context');
        }
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'DatePeriod::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $recv = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $recv->type) {
            throw new \TypeError('DatePeriod::__unserialize() must be called on an object');
        }
        $obj = $recv->toObject();
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'DatePeriod::__unserialize(): Argument #1 ($data) must be of type array, '
                .EnumCaseSupport::typeNameForVariable($arg).' given'
            );
        }
        DatePeriodSupport::restoreFromSetStateHash($frame->vmContext, $arg, $obj);
    }
}

/** php-src DatePeriod::__wakeup (#22596). */
final class DatePeriodWakeup extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__wakeup');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DatePeriod::__wakeup() requires VM context');
        }
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                'DatePeriod::__wakeup() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $recv = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $recv->type) {
            throw new \TypeError('DatePeriod::__wakeup() must be called on an object');
        }
        $obj = $recv->toObject();
        $bag = DateSerializeMethods::objectPropertyBag($obj);
        DatePeriodSupport::restoreFromSetStateHash($frame->vmContext, $bag, $obj);
    }
}
