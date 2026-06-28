<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * MultipleIterator — parallel iteration over multiple iterators (php-src ext/spl/spl_iterators.c; #13173).
 */
final class MultipleIteratorBuiltin
{
    public const CLASS_LC = 'multipleiterator';

    public const MIT_NEED_ANY = 0;

    public const MIT_NEED_ALL = 1;

    public const MIT_KEYS_NUMERIC = 0;

    public const MIT_KEYS_ASSOC = 2;

    private const DEFAULT_FLAGS = self::MIT_NEED_ALL | self::MIT_KEYS_NUMERIC;

    /**
     * @var array<int, array{
     *     flags: int,
     *     iterators: list<array{iterator: ObjectEntry, info: int|string|null}>
     * }>
     */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('MultipleIterator');
        foreach (['Iterator', 'Traversable'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new MultipleIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'attachiterator' => MultipleIteratorAttachIterator::class,
            'detachiterator' => MultipleIteratorDetachIterator::class,
            'containsiterator' => MultipleIteratorContainsIterator::class,
            'countiterators' => MultipleIteratorCountIterators::class,
            'setflags' => MultipleIteratorSetFlags::class,
            'getflags' => MultipleIteratorGetFlags::class,
            'rewind' => MultipleIteratorRewind::class,
            'valid' => MultipleIteratorValid::class,
            'current' => MultipleIteratorCurrent::class,
            'key' => MultipleIteratorKey::class,
            'next' => MultipleIteratorNext::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['attachiterator'] = 'attachIterator';
        $entry->methodNames['detachiterator'] = 'detachIterator';
        $entry->methodNames['containsiterator'] = 'containsIterator';
        $entry->methodNames['countiterators'] = 'countIterators';
        $entry->methodNames['setflags'] = 'setFlags';
        $entry->methodNames['getflags'] = 'getFlags';

        SplClassConstants::registerIntConstants($entry, [
            'MIT_NEED_ANY' => self::MIT_NEED_ANY,
            'MIT_NEED_ALL' => self::MIT_NEED_ALL,
            'MIT_KEYS_NUMERIC' => self::MIT_KEYS_NUMERIC,
            'MIT_KEYS_ASSOC' => self::MIT_KEYS_ASSOC,
        ]);

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['attachiterator'], $entry->methods['rewind'], $entry->methods['valid']);
    }

    public static function init(ObjectEntry $object, int $flags = self::DEFAULT_FLAGS): void
    {
        self::$store[$object->id] = [
            'flags' => $flags,
            'iterators' => [],
        ];
    }

    public static function attachIterator(ObjectEntry $object, ObjectEntry $inner, int|string|null $info): void
    {
        self::ensureState($object);
        self::$store[$object->id]['iterators'][] = [
            'iterator' => $inner,
            'info' => $info,
        ];
    }

    public static function detachIterator(ObjectEntry $object, ObjectEntry $inner): bool
    {
        self::ensureState($object);
        $iterators = &self::$store[$object->id]['iterators'];
        foreach ($iterators as $index => $entry) {
            if ($entry['iterator']->id === $inner->id) {
                unset($iterators[$index]);
                self::$store[$object->id]['iterators'] = array_values($iterators);

                return true;
            }
        }

        return false;
    }

    public static function containsIterator(ObjectEntry $object, ObjectEntry $inner): bool
    {
        self::ensureState($object);

        return self::findIteratorIndex($object, $inner) >= 0;
    }

    public static function countIterators(ObjectEntry $object): int
    {
        self::ensureState($object);

        return \count(self::$store[$object->id]['iterators']);
    }

    public static function setFlags(ObjectEntry $object, int $flags): void
    {
        self::ensureState($object);
        self::$store[$object->id]['flags'] = $flags;
    }

    public static function getFlags(ObjectEntry $object): int
    {
        self::ensureState($object);

        return self::$store[$object->id]['flags'];
    }

    public static function rewind(Frame $frame, ObjectEntry $object): void
    {
        self::ensureState($object);
        foreach (self::$store[$object->id]['iterators'] as $entry) {
            SplDualIteratorStorage::callInner($frame, $entry['iterator'], 'rewind');
        }
    }

    public static function valid(Frame $frame, ObjectEntry $object): bool
    {
        self::ensureState($object);
        $state = self::$store[$object->id];
        if ([] === $state['iterators']) {
            return false;
        }
        $needAll = 0 !== ($state['flags'] & self::MIT_NEED_ALL);
        $anyValid = false;
        foreach ($state['iterators'] as $entry) {
            $valid = SplDualIteratorStorage::callInner($frame, $entry['iterator'], 'valid')
                ->resolveIndirect()->toBool();
            if ($needAll && !$valid) {
                return false;
            }
            if ($valid) {
                $anyValid = true;
            }
        }

        return $anyValid;
    }

    public static function current(Frame $frame, ObjectEntry $object): HashTable
    {
        self::ensureState($object);
        $state = self::$store[$object->id];
        $assoc = 0 !== ($state['flags'] & self::MIT_KEYS_ASSOC);
        $result = new HashTable();
        foreach ($state['iterators'] as $index => $entry) {
            $valid = SplDualIteratorStorage::callInner($frame, $entry['iterator'], 'valid')
                ->resolveIndirect()->toBool();
            if (!$valid) {
                if (0 !== ($state['flags'] & self::MIT_NEED_ALL)) {
                    throw new \RuntimeException('Called current() with non valid sub iterator');
                }
                $value = new Variable();
                $value->null();
            } else {
                if ($assoc && null === $entry['info']) {
                    throw new \InvalidArgumentException('Sub-Iterator is associated with NULL');
                }
                $value = SplDualIteratorStorage::callInner($frame, $entry['iterator'], 'current')
                    ->resolveIndirect();
            }
            if ($assoc) {
                $key = self::infoKey($entry['info']);
                $result->add($key, $value);
            } else {
                $result->append($value);
            }
        }

        return $result;
    }

    public static function key(Frame $frame, ObjectEntry $object): HashTable
    {
        self::ensureState($object);
        $state = self::$store[$object->id];
        $assoc = 0 !== ($state['flags'] & self::MIT_KEYS_ASSOC);
        $result = new HashTable();
        foreach ($state['iterators'] as $entry) {
            $valid = SplDualIteratorStorage::callInner($frame, $entry['iterator'], 'valid')
                ->resolveIndirect()->toBool();
            if (!$valid) {
                if (0 !== ($state['flags'] & self::MIT_NEED_ALL)) {
                    throw new \RuntimeException('Called key() with non valid sub iterator');
                }
                $value = new Variable();
                $value->null();
            } else {
                if ($assoc && null === $entry['info']) {
                    throw new \InvalidArgumentException('Sub-Iterator is associated with NULL');
                }
                $value = SplDualIteratorStorage::callInner($frame, $entry['iterator'], 'key')
                    ->resolveIndirect();
            }
            if ($assoc) {
                $result->add(self::infoKey($entry['info']), $value);
            } else {
                $result->append($value);
            }
        }

        return $result;
    }

    public static function next(Frame $frame, ObjectEntry $object): void
    {
        self::ensureState($object);
        foreach (self::$store[$object->id]['iterators'] as $entry) {
            SplDualIteratorStorage::callInner($frame, $entry['iterator'], 'next');
        }
    }

    private static function infoKey(int|string|null $info): string
    {
        return match (true) {
            \is_int($info) => (string) $info,
            \is_string($info) => $info,
            default => '',
        };
    }

    private static function findIteratorIndex(ObjectEntry $object, ObjectEntry $inner): int
    {
        foreach (self::$store[$object->id]['iterators'] as $index => $entry) {
            if ($entry['iterator']->id === $inner->id) {
                return $index;
            }
        }

        return -1;
    }

    private static function ensureState(ObjectEntry $object): void
    {
        if (!isset(self::$store[$object->id])) {
            self::init($object);
        }
    }
}

final class MultipleIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::__construct()'
        );
        $flags = MultipleIteratorBuiltin::MIT_NEED_ALL | MultipleIteratorBuiltin::MIT_KEYS_NUMERIC;
        if (isset($frame->calledArgs[1])) {
            $flags = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'MultipleIterator::__construct',
                0,
                'flags'
            );
        }
        MultipleIteratorBuiltin::init($object, $flags);
    }
}

final class MultipleIteratorAttachIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('attachIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::attachIterator()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'MultipleIterator::attachIterator() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('MultipleIterator::attachIterator() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        $info = null;
        if (isset($frame->calledArgs[2])) {
            $infoVar = $frame->calledArgs[2]->resolveIndirect();
            $info = match ($infoVar->type) {
                Variable::TYPE_INTEGER => $infoVar->toInt(),
                Variable::TYPE_STRING => $infoVar->toString(),
                Variable::TYPE_NULL => null,
                default => throw new \TypeError(
                    'MultipleIterator::attachIterator(): Argument #2 ($info) must be of type string|int|null, '
                    .self::typeLabel($infoVar).' given'
                ),
            };
        }
        MultipleIteratorBuiltin::attachIterator($object, $inner, $info);
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOL => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}

final class MultipleIteratorDetachIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('detachIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::detachIterator()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'MultipleIterator::detachIterator() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('MultipleIterator::detachIterator() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        SplIteratorSupport::setReturnBool(
            $frame,
            MultipleIteratorBuiltin::detachIterator($object, $inner)
        );
    }
}

final class MultipleIteratorContainsIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('containsIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::containsIterator()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'MultipleIterator::containsIterator() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('MultipleIterator::containsIterator() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        SplIteratorSupport::setReturnBool(
            $frame,
            MultipleIteratorBuiltin::containsIterator($object, $inner)
        );
    }
}

final class MultipleIteratorCountIterators extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('countIterators');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::countIterators()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(MultipleIteratorBuiltin::countIterators($object));
    }
}

final class MultipleIteratorSetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::setFlags()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'MultipleIterator::setFlags() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $flags = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'MultipleIterator::setFlags',
            0,
            'flags'
        );
        MultipleIteratorBuiltin::setFlags($object, $flags);
    }
}

final class MultipleIteratorGetFlags extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getFlags');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::getFlags()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(MultipleIteratorBuiltin::getFlags($object));
    }
}

final class MultipleIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::rewind()'
        );
        MultipleIteratorBuiltin::rewind($frame, $object);
    }
}

final class MultipleIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, MultipleIteratorBuiltin::valid($frame, $object));
    }
}

final class MultipleIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::current()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(MultipleIteratorBuiltin::current($frame, $object));
    }
}

final class MultipleIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(MultipleIteratorBuiltin::key($frame, $object));
    }
}

final class MultipleIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            MultipleIteratorBuiltin::CLASS_LC,
            'MultipleIterator::next()'
        );
        MultipleIteratorBuiltin::next($frame, $object);
    }
}
