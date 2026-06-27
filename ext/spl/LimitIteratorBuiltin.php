<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * LimitIterator — offset/limit wrapper over inner Iterator (php-src ext/spl/spl_iterators.c; #12893).
 */
final class LimitIteratorBuiltin
{
    public const CLASS_LC = 'limititerator';

    public static function registerClass(Context $ctx): void
    {
        IteratorIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('LimitIterator');
        $entry->parentLc = IteratorIteratorBuiltin::CLASS_LC;
        foreach (['OuterIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new LimitIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        foreach ([
            'rewind' => LimitIteratorRewind::class,
            'valid' => LimitIteratorValid::class,
            'current' => LimitIteratorCurrent::class,
            'key' => LimitIteratorKey::class,
            'next' => LimitIteratorNext::class,
            'getinneriterator' => LimitIteratorGetInnerIterator::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['valid'], $entry->methods['__construct']);
    }
}

/** @internal */
final class SplLimitIteratorStorage
{
    /** @var array<int, array{inner: ObjectEntry, offset: int, limit: int, pos: int}> */
    private static array $store = [];

    public static function init(ObjectEntry $object, ObjectEntry $inner, int $offset, int $limit): void
    {
        self::$store[$object->id] = [
            'inner' => $inner,
            'offset' => $offset,
            'limit' => $limit,
            'pos' => 0,
        ];
    }

    public static function inner(ObjectEntry $object): ObjectEntry
    {
        return self::state($object)['inner'];
    }

    public static function rewind(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        $state['pos'] = 0;
        SplDualIteratorStorage::callInner($frame, $state['inner'], 'rewind');
        for ($i = 0; $i < $state['offset']; ++$i) {
            $valid = SplDualIteratorStorage::callInner($frame, $state['inner'], 'valid')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $valid->type || !$valid->toBool()) {
                break;
            }
            SplDualIteratorStorage::callInner($frame, $state['inner'], 'next');
            ++$state['pos'];
        }
    }

    public static function valid(Frame $frame, ObjectEntry $object): bool
    {
        $state = self::state($object);
        if (-1 !== $state['limit'] && self::relativePos($state) >= $state['limit']) {
            return false;
        }
        $valid = SplDualIteratorStorage::callInner($frame, $state['inner'], 'valid')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $valid->type && $valid->toBool();
    }

    public static function current(Frame $frame, ObjectEntry $object): Variable
    {
        return SplDualIteratorStorage::callInner($frame, self::state($object)['inner'], 'current');
    }

    public static function key(Frame $frame, ObjectEntry $object): Variable
    {
        return SplDualIteratorStorage::callInner($frame, self::state($object)['inner'], 'key');
    }

    public static function next(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        if (-1 !== $state['limit'] && self::relativePos($state) >= $state['limit']) {
            return;
        }
        SplDualIteratorStorage::callInner($frame, $state['inner'], 'next');
        ++$state['pos'];
    }

    /** @param array{inner: ObjectEntry, offset: int, limit: int, pos: int} $state */
    private static function relativePos(array $state): int
    {
        return $state['pos'] - $state['offset'];
    }

    /** @return array{inner: ObjectEntry, offset: int, limit: int, pos: int} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('Iterator wrapper state missing');
        }

        return self::$store[$object->id];
    }
}

final class LimitIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'LimitIterator::__construct() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('LimitIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        $offset = 0;
        if (isset($frame->calledArgs[2])) {
            $offsetArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $offsetArg->type) {
                throw new \TypeError(
                    'LimitIterator::__construct(): Argument #2 ($offset) must be of type int, '
                    .self::typeLabel($offsetArg).' given'
                );
            }
            $offset = $offsetArg->toInt();
            if ($offset < 0) {
                throw new \ValueError(
                    'LimitIterator::__construct(): Argument #2 ($offset) must be greater than or equal to 0'
                );
            }
        }
        $limit = -1;
        if (isset($frame->calledArgs[3])) {
            $limitArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitArg->type) {
                throw new \TypeError(
                    'LimitIterator::__construct(): Argument #3 ($limit) must be of type int, '
                    .self::typeLabel($limitArg).' given'
                );
            }
            $limit = $limitArg->toInt();
            if ($limit < -1) {
                throw new \ValueError(
                    'LimitIterator::__construct(): Argument #3 ($limit) must be greater than or equal to -1'
                );
            }
        }
        SplLimitIteratorStorage::init($object, $inner, $offset, $limit);
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_DOUBLE => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}

final class LimitIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::rewind()'
        );
        SplLimitIteratorStorage::rewind($frame, $object);
    }
}

final class LimitIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::valid()'
        );
        SplIteratorSupport::setReturnBool($frame, SplLimitIteratorStorage::valid($frame, $object));
    }
}

final class LimitIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::current()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplLimitIteratorStorage::current($frame, $object));
    }
}

final class LimitIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::key()'
        );
        SplIteratorSupport::copyReturnFrom($frame, SplLimitIteratorStorage::key($frame, $object));
    }
}

final class LimitIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::next()'
        );
        SplLimitIteratorStorage::next($frame, $object);
    }
}

final class LimitIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::getInnerIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $inner = SplLimitIteratorStorage::inner($object);
        $frame->returnVar->object($inner);
    }
}
