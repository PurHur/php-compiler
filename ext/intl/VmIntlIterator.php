<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IntlIterator — ICU StringEnumeration wrapper (php-src ext/intl/common/common_enum.cpp; #20909).
 *
 * In-memory list iterator used by IntlCalendar::getKeywordValuesForLocale and
 * IntlTimeZone::createEnumeration / createTimeZoneIDEnumeration.
 */
final class VmIntlIterator
{
    public const CLASS_LC = 'intliterator';

    /** @var array<int, array{values: list<string>, index: int}> */
    private static array $state = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('IntlIterator');
        $entry->isInternal = true;
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }

        $entry->constructor = new IntlIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methodNames['__construct'] = '__construct';
        $entry->methods['current'] = new IntlIteratorCurrent();
        $entry->methodVisibility['current'] = $pub;
        $entry->methodNames['current'] = 'current';
        $entry->methods['key'] = new IntlIteratorKey();
        $entry->methodVisibility['key'] = $pub;
        $entry->methodNames['key'] = 'key';
        $entry->methods['next'] = new IntlIteratorNext();
        $entry->methodVisibility['next'] = $pub;
        $entry->methodNames['next'] = 'next';
        $entry->methods['rewind'] = new IntlIteratorRewind();
        $entry->methodVisibility['rewind'] = $pub;
        $entry->methodNames['rewind'] = 'rewind';
        $entry->methods['valid'] = new IntlIteratorValid();
        $entry->methodVisibility['valid'] = $pub;
        $entry->methodNames['valid'] = 'valid';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @param list<string> $values
     */
    public static function fromStringList(Context $ctx, array $values): ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "IntlIterator" not found');
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        self::bindValues($object, $values);

        return $object;
    }

    /**
     * @param list<string> $values
     */
    public static function bindValues(ObjectEntry $object, array $values): void
    {
        $object->constructed = true;
        self::$state[$object->id] = [
            'values' => array_values($values),
            'index' => 0,
        ];
    }

    public static function isIteratorObject(ObjectEntry $object): bool
    {
        return self::CLASS_LC === strtolower($object->class->name);
    }

    public static function rewind(ObjectEntry $object): void
    {
        $st = &self::stateRef($object);
        $st['index'] = 0;
    }

    public static function next(ObjectEntry $object): void
    {
        $st = &self::stateRef($object);
        ++$st['index'];
    }

    public static function valid(ObjectEntry $object): bool
    {
        $st = self::stateRef($object);

        return $st['index'] >= 0 && $st['index'] < \count($st['values']);
    }

    public static function current(ObjectEntry $object): string|false
    {
        $st = self::stateRef($object);
        if ($st['index'] < 0 || $st['index'] >= \count($st['values'])) {
            return false;
        }

        return $st['values'][$st['index']];
    }

    public static function key(ObjectEntry $object): int
    {
        return self::stateRef($object)['index'];
    }

    /**
     * @return array{values: list<string>, index: int}
     */
    private static function &stateRef(ObjectEntry $object): array
    {
        if (!isset(self::$state[$object->id])) {
            self::$state[$object->id] = [
                'values' => [],
                'index' => 0,
            ];
        }

        return self::$state[$object->id];
    }

    public static function receiver(Frame $frame, string $method): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($method.' called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException($method.' called on non-object');
        }
        $object = $receiver->toObject();
        if (!self::isIteratorObject($object)) {
            throw new \LogicException($method.' called on incompatible object');
        }

        return $object;
    }
}

final class IntlIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        // Userland construction yields an empty enumeration (php-src has no public factory).
        VmIntlIterator::bindValues(
            VmIntlIterator::receiver($frame, 'IntlIterator::__construct()'),
            []
        );
    }
}

final class IntlIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = VmIntlIterator::receiver($frame, 'IntlIterator::current()');
        if (null === $frame->returnVar) {
            return;
        }
        $value = VmIntlIterator::current($object);
        if (false === $value) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($value);
    }
}

final class IntlIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = VmIntlIterator::receiver($frame, 'IntlIterator::key()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmIntlIterator::key($object));
    }
}

final class IntlIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = VmIntlIterator::receiver($frame, 'IntlIterator::next()');
        VmIntlIterator::next($object);
    }
}

final class IntlIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = VmIntlIterator::receiver($frame, 'IntlIterator::rewind()');
        VmIntlIterator::rewind($object);
    }
}

final class IntlIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = VmIntlIterator::receiver($frame, 'IntlIterator::valid()');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmIntlIterator::valid($object));
    }
}
