<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * ArrayIterator — array as Iterator (php-src ext/spl/spl_array.c; #6304).
 */
final class ArrayIteratorBuiltin
{
    public const CLASS_LC = 'arrayiterator';

    /** @var array<int, array{keys: list<int|string>, table: HashTable, pos: int}> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('ArrayIterator');
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }

        $entry->constructor = new ArrayIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['current'] = new ArrayIteratorCurrent();
        $entry->methodVisibility['current'] = $pub;
        $entry->methods['key'] = new ArrayIteratorKey();
        $entry->methodVisibility['key'] = $pub;
        $entry->methods['next'] = new ArrayIteratorNext();
        $entry->methodVisibility['next'] = $pub;
        $entry->methods['rewind'] = new ArrayIteratorRewind();
        $entry->methodVisibility['rewind'] = $pub;
        $entry->methods['valid'] = new ArrayIteratorValid();
        $entry->methodVisibility['valid'] = $pub;
        $entry->methods['count'] = new ArrayIteratorCount();
        $entry->methodVisibility['count'] = $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function init(ObjectEntry $object, HashTable $table): void
    {
        $keys = [];
        foreach ($table->iterateKeyed(true) as [$keyVar, $_]) {
            $keys[] = Variable::TYPE_INTEGER === $keyVar->type
                ? $keyVar->toInt()
                : $keyVar->toString();
        }
        self::$store[$object->id] = [
            'keys' => $keys,
            'table' => $table,
            'pos' => 0,
        ];
    }

    public static function rewind(ObjectEntry $object): void
    {
        self::$store[$object->id]['pos'] = 0;
    }

    public static function next(ObjectEntry $object): void
    {
        ++self::$store[$object->id]['pos'];
    }

    public static function valid(ObjectEntry $object): bool
    {
        $state = self::state($object);

        return $state['pos'] >= 0 && $state['pos'] < \count($state['keys']);
    }

    public static function current(ObjectEntry $object): Variable
    {
        $state = self::state($object);
        if (!self::valid($object)) {
            throw new \RuntimeException('Cannot fetch current() on invalid ArrayIterator position');
        }
        $key = $state['keys'][$state['pos']];
        if (\is_int($key)) {
            $var = $state['table']->findIndex($key);
        } else {
            $var = $state['table']->find((string) $key);
        }
        if (null === $var) {
            throw new \LogicException('ArrayIterator current key missing from backing array');
        }

        return $var;
    }

    public static function key(ObjectEntry $object): int|string
    {
        $state = self::state($object);
        if (!self::valid($object)) {
            throw new \RuntimeException('Cannot fetch key() on invalid ArrayIterator position');
        }

        return $state['keys'][$state['pos']];
    }

    public static function count(ObjectEntry $object): int
    {
        return \count(self::state($object)['keys']);
    }

    /** @return array{keys: list<int|string>, table: HashTable, pos: int} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('ArrayIterator state missing');
        }

        return self::$store[$object->id];
    }
}

final class ArrayIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ArrayIterator::__construct() expects at least 1 argument');
        }
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::__construct()'
        );
        $table = SplIteratorSupport::requireArrayArg(
            $frame->calledArgs[1],
            'ArrayIterator::__construct',
            1
        );
        ArrayIteratorBuiltin::init($object, $table);
    }
}

final class ArrayIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::rewind()'
        );
        ArrayIteratorBuiltin::rewind($object);
    }
}

final class ArrayIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::next()'
        );
        ArrayIteratorBuiltin::next($object);
    }
}

final class ArrayIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, ArrayIteratorBuiltin::valid($object));
    }
}

final class ArrayIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, ArrayIteratorBuiltin::current($object));
    }
}

final class ArrayIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::key()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $key = ArrayIteratorBuiltin::key($object);
        if (\is_int($key)) {
            $frame->returnVar->int($key);
        } else {
            $frame->returnVar->string((string) $key);
        }
    }
}

final class ArrayIteratorCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayIteratorBuiltin::CLASS_LC,
            'ArrayIterator::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(ArrayIteratorBuiltin::count($object));
    }
}
