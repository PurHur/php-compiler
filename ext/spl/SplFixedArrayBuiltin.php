<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\ext\standard\VmJson;
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
 * SplFixedArray — fixed-size array access (php-src ext/spl/spl_fixedarray.c; #12628).
 */
final class SplFixedArrayBuiltin
{
    public const CLASS_LC = 'splfixedarray';

    private const RANGE_ERROR = 'Index invalid or out of range';

    /** @var array<int, array{size: int, slots: array<int, Variable>}> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplFixedArray');
        foreach (['iteratoraggregate', 'traversable', 'arrayaccess', 'countable', 'jsonserializable'] as $iface) {
            if (isset($ctx->classes[$iface])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SplFixedArrayConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'count' => SplFixedArrayCount::class,
            'offsetget' => SplFixedArrayOffsetGet::class,
            'offsetset' => SplFixedArrayOffsetSet::class,
            'offsetexists' => SplFixedArrayOffsetExists::class,
            'offsetunset' => SplFixedArrayOffsetUnset::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['offsetget'] = 'offsetGet';
        $entry->methodNames['offsetset'] = 'offsetSet';
        $entry->methodNames['offsetexists'] = 'offsetExists';
        $entry->methodNames['offsetunset'] = 'offsetUnset';
        // php-src spl_fixedarray.stub.php — untyped $index; @tentative-return-type (#25856).
        SplArrayStorage::attachArrayAccessArginfoNamed($entry, 'index', null, 'value', 'mixed');
        $entry->methods['fromarray'] = new SplFixedArrayFromArray();
        $entry->methodVisibility['fromarray'] = $pubStatic;
        $entry->methodNames['fromarray'] = 'fromArray';
        // php-src spl_fixedarray.stub.php — fromArray(): SplFixedArray (#26793).
        $entry->methodReturnDeclaredTypes['fromarray'] = 'SplFixedArray';
        $entry->methods['toarray'] = new SplFixedArrayToArray();
        $entry->methodVisibility['toarray'] = $pub;
        $entry->methodNames['toarray'] = 'toArray';
        $entry->methods['getiterator'] = new SplFixedArrayGetIterator();
        $entry->methodVisibility['getiterator'] = $pub;
        $entry->methodNames['getiterator'] = 'getIterator';
        $entry->methods['getsize'] = new SplFixedArrayGetSize();
        $entry->methodVisibility['getsize'] = $pub;
        $entry->methodNames['getsize'] = 'getSize';
        $entry->methods['setsize'] = new SplFixedArraySetSize();
        $entry->methodVisibility['setsize'] = $pub;
        $entry->methodNames['setsize'] = 'setSize';
        $entry->methods['jsonserialize'] = new SplFixedArrayJsonSerialize();
        $entry->methodVisibility['jsonserialize'] = $pub;
        $entry->methodNames['jsonserialize'] = 'jsonSerialize';
        $entry->methods['__serialize'] = new SplFixedArraySerialize();
        $entry->methodVisibility['__serialize'] = $pub;
        $entry->methods['__unserialize'] = new SplFixedArrayUnserialize();
        $entry->methodVisibility['__unserialize'] = $pub;
        $entry->methods['__debuginfo'] = new SplFixedArrayDebugInfo();
        $entry->methodVisibility['__debuginfo'] = $pub;
        $entry->methodNames['__debuginfo'] = '__debugInfo';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['count'], $entry->methods['offsetexists'], $entry->methods['fromarray'], $entry->methods['getiterator'], $entry->methods['getsize'], $entry->methods['jsonserialize'], $entry->methods['__serialize'], $entry->methods['__debuginfo']);
    }

    public static function hasState(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]);
    }

    /**
     * @param array<int, mixed> $exported
     */
    public static function restoreExportedState(ObjectEntry $object, array $exported): void
    {
        $maxIndex = -1;
        foreach ($exported as $key => $raw) {
            if (!\is_int($key)) {
                throw new \TypeError('SplFixedArray::__unserialize(): invalid array key');
            }
            if ($key < 0) {
                throw new \TypeError('SplFixedArray::__unserialize(): invalid array key');
            }
            if ($key > $maxIndex) {
                $maxIndex = $key;
            }
        }
        $size = $maxIndex + 1;
        if (!self::hasState($object)) {
            self::init($object, $size);
        } else {
            self::setSize($object, $size);
        }
        self::$store[$object->id]['slots'] = [];
        foreach ($exported as $index => $raw) {
            $slot = new Variable();
            $slot->copyFrom(VmJson::import($raw));
            self::$store[$object->id]['slots'][(int) $index] = $slot;
        }
    }

    public static function init(ObjectEntry $object, int $size): void
    {
        if ($size < 0) {
            throw new \ValueError('SplFixedArray::__construct(): Argument #1 ($size) must be greater than or equal to 0');
        }
        self::$store[$object->id] = ['size' => $size, 'slots' => []];
    }

    public static function count(ObjectEntry $object): int
    {
        return self::state($object)['size'];
    }

    public static function getSize(ObjectEntry $object): int
    {
        return self::state($object)['size'];
    }

    public static function setSize(ObjectEntry $object, int $size): void
    {
        if ($size < 0) {
            throw new \ValueError('SplFixedArray::setSize(): Argument #1 ($size) must be greater than or equal to 0');
        }
        $oldSize = self::state($object)['size'];
        if ($size === $oldSize) {
            return;
        }
        if ($size < $oldSize) {
            for ($i = $size; $i < $oldSize; ++$i) {
                unset(self::$store[$object->id]['slots'][$i]);
            }
        }
        self::$store[$object->id]['size'] = $size;
    }

    public static function offsetExists(ObjectEntry $object, Variable $offset): bool
    {
        $index = self::coerceOffset($offset);
        if (!self::indexInRange($object, $index)) {
            return false;
        }
        if (!isset(self::state($object)['slots'][$index])) {
            return false;
        }
        // php-src spl_fixedarray_object_has_dimension — null slots are non-existent (#24255).
        $slot = self::state($object)['slots'][$index]->resolveIndirect();

        return !$slot->isUndefined() && Variable::TYPE_NULL !== $slot->type;
    }

    public static function offsetGet(ObjectEntry $object, Variable $offset): Variable
    {
        $index = self::coerceOffset($offset);
        if (!self::indexInRange($object, $index)) {
            self::throwRangeError();
        }
        $result = new Variable();
        if (isset(self::state($object)['slots'][$index])) {
            $result->copyFrom(self::state($object)['slots'][$index]);
        } else {
            $result->null();
        }

        return $result;
    }

    public static function offsetSet(ObjectEntry $object, Variable $offset, Variable $value): void
    {
        $index = self::coerceOffset($offset);
        if (!self::indexInRange($object, $index)) {
            self::throwRangeError();
        }
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());
        self::$store[$object->id]['slots'][$index] = $copy;
    }

    public static function offsetUnset(ObjectEntry $object, Variable $offset): void
    {
        $index = self::coerceOffset($offset);
        if (!self::indexInRange($object, $index)) {
            self::throwRangeError();
        }
        unset(self::$store[$object->id]['slots'][$index]);
    }

    /**
     * php-src 8.4+ throws OutOfBoundsException; older lines keep RuntimeException (#28819).
     */
    private static function throwRangeError(): void
    {
        if (CompilerVersion::supportsSplFixedArrayOutOfBoundsException()) {
            throw new \OutOfBoundsException(self::RANGE_ERROR);
        }

        throw new \RuntimeException(self::RANGE_ERROR);
    }

    /**
     * @return array<int, Variable>
     */
    public static function toArray(ObjectEntry $object): HashTable
    {
        $state = self::state($object);
        $size = $state['size'];
        if (0 === $size) {
            return new HashTable();
        }
        $values = [];
        for ($i = 0; $i < $size; ++$i) {
            $slot = new Variable();
            if (isset($state['slots'][$i])) {
                $slot->copyFrom($state['slots'][$i]);
            } else {
                $slot->null();
            }
            $values[] = $slot;
        }
        $ht = new HashTable();
        $ht->assignPackedList($values);

        return $ht;
    }

    public static function fromArray(Context $ctx, HashTable $data, bool $saveIndexes): ObjectEntry
    {
        $num = $data->getNumElements();
        $size = 0;
        /** @var array<int, Variable> $indexedValues */
        $indexedValues = [];

        if ($num > 0 && $saveIndexes) {
            $maxIndex = 0;
            foreach ($data->iterateKeyed(true) as [$keyVar, $valueVar]) {
                $index = self::coercePositiveArrayKey($keyVar);
                if ($index > $maxIndex) {
                    $maxIndex = $index;
                }
                $copy = new Variable();
                $copy->copyFrom($valueVar->resolveIndirect());
                $indexedValues[$index] = $copy;
            }
            $size = $maxIndex + 1;
            if ($size <= 0) {
                throw new \InvalidArgumentException('integer overflow detected');
            }
        } elseif ($num > 0) {
            $size = $num;
            $i = 0;
            foreach ($data->iterateKeyed(true) as [, $valueVar]) {
                $copy = new Variable();
                $copy->copyFrom($valueVar->resolveIndirect());
                $indexedValues[$i++] = $copy;
            }
        }

        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('SplFixedArray is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::init($object, $size);
        foreach ($indexedValues as $index => $var) {
            self::$store[$object->id]['slots'][$index] = $var;
        }

        return $object;
    }

    /**
     * SplFixedArray::getIterator — InternalIterator snapshot (php-src spl_fixedarray.c; #23168).
     *
     * Snapshot at call time (same as DatePeriod / WeakMap table-backed InternalIterator).
     */
    public static function createIterator(Context $ctx, ObjectEntry $object): Variable
    {
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object(InternalIteratorBuiltin::fromTable($ctx, self::toArray($object)));

        return $var;
    }

    /** @throws \InvalidArgumentException */
    private static function coercePositiveArrayKey(Variable $keyVar): int
    {
        $key = $keyVar->resolveIndirect();
        if (Variable::TYPE_INTEGER === $key->type) {
            $index = $key->toInt();
            if ($index < 0) {
                throw new \InvalidArgumentException('array must contain only positive integer keys');
            }

            return $index;
        }
        if (Variable::TYPE_STRING === $key->type) {
            $s = $key->toString();
            if (!preg_match('/^\d+$/', $s)) {
                throw new \InvalidArgumentException('array must contain only positive integer keys');
            }
            $index = (int) $s;
            if ((string) $index !== $s) {
                throw new \InvalidArgumentException('array must contain only positive integer keys');
            }

            return $index;
        }

        throw new \InvalidArgumentException('array must contain only positive integer keys');
    }

    /** @return array{size: int, slots: array<int, Variable>} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('SplFixedArray object state missing');
        }

        return self::$store[$object->id];
    }

    private static function indexInRange(ObjectEntry $object, int $index): bool
    {
        return $index >= 0 && $index < self::state($object)['size'];
    }

    private static function coerceOffset(Variable $offset): int
    {
        $resolved = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $resolved->type) {
            throw new \TypeError(
                'SplFixedArray offset must be of type int, '
                .match ($resolved->type) {
                    Variable::TYPE_NULL => 'null',
                    Variable::TYPE_BOOLEAN => 'bool',
                    Variable::TYPE_FLOAT => 'float',
                    Variable::TYPE_STRING => 'string',
                    Variable::TYPE_ARRAY => 'array',
                    Variable::TYPE_OBJECT => 'object',
                    default => 'mixed',
                }.' given'
            );
        }

        return $resolved->toInt();
    }
}

/**
 * SplFixedArray::__debugInfo() — indexed element map for var_dump/print_r
 * (php-src spl_fixedarray_object_debug_info; #19783).
 */
final class SplFixedArrayDebugInfo extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__debugInfo');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::__debugInfo()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplFixedArrayBuiltin::toArray($object));
    }
}

final class SplFixedArrayConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::__construct()'
        );
        $size = 0;
        if (isset($frame->calledArgs[1])) {
            // php-src Z_PARAM_LONG $size — 1-based param index; soft-null DEP outside strict (#31623).
            $size = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'SplFixedArray::__construct',
                1,
                'size'
            );
        }
        SplFixedArrayBuiltin::init($object, $size);
    }
}

final class SplFixedArrayCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::count()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — SplFixedArray::count() (#20162)
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'SplFixedArray::count() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplFixedArrayBuiltin::count($object));
    }
}

final class SplFixedArrayOffsetGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetGet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::offsetGet()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30997)
        $this->requireExactUserArgCount($frame, 'SplFixedArray::offsetGet', 1);
        SplIteratorSupport::copyReturnFrom(
            $frame,
            SplFixedArrayBuiltin::offsetGet($object, $frame->calledArgs[1])
        );
    }
}

final class SplFixedArrayOffsetSet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetSet');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::offsetSet()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(2, 2) (#30997)
        $this->requireExactUserArgCount($frame, 'SplFixedArray::offsetSet', 2);
        SplFixedArrayBuiltin::offsetSet($object, $frame->calledArgs[1], $frame->calledArgs[2]);
    }
}

final class SplFixedArrayOffsetExists extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetExists');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::offsetExists()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30997)
        $this->requireExactUserArgCount($frame, 'SplFixedArray::offsetExists', 1);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(
            SplFixedArrayBuiltin::offsetExists($object, $frame->calledArgs[1])
        );
    }
}

final class SplFixedArrayOffsetUnset extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('offsetUnset');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::offsetUnset()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30997)
        $this->requireExactUserArgCount($frame, 'SplFixedArray::offsetUnset', 1);
        SplFixedArrayBuiltin::offsetUnset($object, $frame->calledArgs[1]);
    }
}

final class SplFixedArrayFromArray extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromArray');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Static method — frame args are user args only (#30836; zim_SplFixedArray_fromArray).
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'SplFixedArray::fromArray() expects at least 1 argument, 0 given'
            );
        }
        if ($argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('SplFixedArray::fromArray() expects at most 2 arguments, %d given', $argc)
            );
        }
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SplFixedArray::fromArray() requires VM context');
        }
        $data = VmArray::requireArrayParam($frame->calledArgs[0], 'SplFixedArray::fromArray', 1, 'array');
        $saveIndexes = true;
        if (isset($frame->calledArgs[1])) {
            // php-src Z_PARAM_BOOL $preserveKeys — soft-null DEP+false outside strict_types (#31647).
            $saveIndexes = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                1,
                'SplFixedArray::fromArray',
                2,
                'preserveKeys'
            );
        }
        $object = SplFixedArrayBuiltin::fromArray($ctx, $data, $saveIndexes);
        $result = new Variable(Variable::TYPE_OBJECT);
        $result->object($object);
        SplIteratorSupport::copyReturnFrom($frame, $result);
    }
}

final class SplFixedArrayToArray extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('toArray');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::toArray()'
        );
        // User arity excludes $this (#30836; ZEND_PARSE_PARAMETERS_NONE).
        $this->requireExactUserArgCount($frame, 'SplFixedArray::toArray', 0);
        $result = new Variable(Variable::TYPE_ARRAY);
        $result->array(SplFixedArrayBuiltin::toArray($object));
        SplIteratorSupport::copyReturnFrom($frame, $result);
    }
}

final class SplFixedArrayGetIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::getIterator()'
        );
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException('SplFixedArray::getIterator() requires VM context');
        }
        SplIteratorSupport::copyReturnFrom($frame, SplFixedArrayBuiltin::createIterator($ctx, $object));
    }
}

final class SplFixedArrayGetSize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::getSize()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE — SplFixedArray::getSize() (#20162)
        if (\count($frame->calledArgs) > 1) {
            throw new \ArgumentCountError(
                'SplFixedArray::getSize() expects exactly 0 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplFixedArrayBuiltin::getSize($object));
    }
}

final class SplFixedArraySetSize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setSize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::setSize()'
        );
        // User arity excludes $this (#30836; zim_SplFixedArray_setSize); Z_PARAM_LONG soft-null cite #1 (#31807).
        $this->requireExactUserArgCount($frame, 'SplFixedArray::setSize', 1);
        $size = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            1,
            'SplFixedArray::setSize',
            1,
            'size'
        );
        SplFixedArrayBuiltin::setSize($object, $size);
    }
}

final class SplFixedArrayJsonSerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('jsonSerialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::jsonSerialize()'
        );
        $result = new Variable(Variable::TYPE_ARRAY);
        $result->array(SplFixedArrayBuiltin::toArray($object));
        SplIteratorSupport::copyReturnFrom($frame, $result);
    }
}

final class SplFixedArraySerialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__serialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::__serialize()'
        );
        $exported = SplFixedArraySerializeSupport::exportElements($object);
        $result = new Variable(Variable::TYPE_ARRAY);
        $result->newArray();
        $ht = $result->toArray();
        foreach ($exported as $index => $value) {
            $slot = new Variable();
            $slot->copyFrom(VmJson::import($value));
            $ht->assignIndex((int) $index, $slot);
        }
        SplIteratorSupport::copyReturnFrom($frame, $result);
    }
}

final class SplFixedArrayUnserialize extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__unserialize');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplFixedArrayBuiltin::CLASS_LC,
            'SplFixedArray::__unserialize()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplFixedArray::__unserialize() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $arg = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arg->type) {
            throw new \TypeError(
                'SplFixedArray::__unserialize(): Argument #1 ($data) must be of type array'
            );
        }
        $exported = [];
        foreach ($arg->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
            $key = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $key->type) {
                throw new \TypeError('SplFixedArray::__unserialize(): invalid array key');
            }
            $exported[$key->toInt()] = VmJson::export($valueVar->resolveIndirect());
        }
        SplFixedArrayBuiltin::restoreExportedState($object, $exported);
    }
}
