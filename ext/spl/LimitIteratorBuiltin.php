<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmMath;
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
        // Zend rematerializes flattened ce->interfaces (not OuterIterator-first parent walk).
        // LimitIterator::seek exists but the class is not SeekableIterator (#25798).
        // php-src ext/spl/spl_iterators.stub.php / ce->interfaces.
        $entry->interfaces = [];
        foreach (['iterator', 'traversable', 'outeriterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
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
            'seek' => LimitIteratorSeek::class,
            'getposition' => LimitIteratorGetPosition::class,
            'getinneriterator' => LimitIteratorGetInnerIterator::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getposition'] = 'getPosition';
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['rewind'],
            $entry->methods['valid'],
            $entry->methods['seek'],
            $entry->methods['getposition'],
            $entry->methods['__construct']
        );
    }
}

/** @internal */
final class SplLimitIteratorStorage
{
    /** @var array<int, array{inner: ObjectEntry, offset: int, limit: int, pos: int, rewound: bool}> */
    private static array $store = [];

    public static function init(ObjectEntry $object, ObjectEntry $inner, int $offset, int $limit): void
    {
        $pinKey = 'limit:'.$object->id.':inner';
        if (isset(self::$store[$object->id]['innerPinKey'])) {
            SplIteratorSupport::unpinObject(
                self::$store[$object->id]['inner'],
                self::$store[$object->id]['innerPinKey']
            );
        }
        self::$store[$object->id] = [
            'inner' => SplIteratorSupport::pinObject($inner, $pinKey),
            'offset' => $offset,
            'limit' => $limit,
            'pos' => 0,
            'rewound' => false,
            'innerPinKey' => $pinKey,
        ];
    }

    public static function inner(ObjectEntry $object): ObjectEntry
    {
        return self::state($object)['inner'];
    }

    public static function rewind(Frame $frame, ObjectEntry $object): void
    {
        // php-src LimitIterator::rewind — dual_it_rewind then spl_limit_it_seek(offset).
        // SeekableIterator inners (e.g. ArrayIterator) throw OutOfBoundsException when
        // offset is past the end; non-seekable inners advance via next() without throwing (#24295).
        $state = &self::$store[$object->id];
        $state['rewound'] = true;
        $state['pos'] = 0;
        SplDualIteratorStorage::callInner($frame, $state['inner'], 'rewind');
        self::seek($frame, $object, $state['offset']);
    }

    public static function valid(Frame $frame, ObjectEntry $object): bool
    {
        $state = self::state($object);
        if (!$state['rewound']) {
            return false;
        }
        if (-1 !== $state['limit'] && self::relativePos($state) >= $state['limit']) {
            return false;
        }
        $valid = SplDualIteratorStorage::callInner($frame, $state['inner'], 'valid')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $valid->type && $valid->toBool();
    }

    public static function current(Frame $frame, ObjectEntry $object): Variable
    {
        // php-src proxies current only while limit window is valid (#24271).
        if (!self::valid($frame, $object)) {
            $null = new Variable(Variable::TYPE_NULL);
            $null->null();

            return $null;
        }

        return SplDualIteratorStorage::callInner($frame, self::state($object)['inner'], 'current');
    }

    public static function key(Frame $frame, ObjectEntry $object): Variable
    {
        if (!self::valid($frame, $object)) {
            $null = new Variable(Variable::TYPE_NULL);
            $null->null();

            return $null;
        }

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

    public static function position(ObjectEntry $object): int
    {
        return self::state($object)['pos'];
    }

    public static function seek(Frame $frame, ObjectEntry $object, int $pos): void
    {
        $state = &self::$store[$object->id];
        if ($pos < $state['offset']) {
            throw new \OutOfBoundsException(
                \sprintf('Cannot seek to %d which is below the offset %d', $pos, $state['offset'])
            );
        }
        $relative = $pos - $state['offset'];
        if (-1 !== $state['limit'] && $relative >= $state['limit']) {
            throw new \OutOfBoundsException(
                \sprintf(
                    'Cannot seek to %d which is behind offset %d plus count %d',
                    $pos,
                    $state['offset'],
                    $state['limit']
                )
            );
        }

        $state['rewound'] = true;
        $inner = $state['inner'];
        if ($pos !== $state['pos'] && self::innerHasSeek($frame, $inner)) {
            // Call storage seek directly — nested invokeInstanceMethod(ArrayIterator::seek)
            // finds the outer user catch and is swallowed as MagicMethodInvocationAborted (#24295).
            self::seekInnerDirect($frame, $inner, $pos);
            $state['pos'] = $pos;
        } elseif ($pos < $state['pos']) {
            SplDualIteratorStorage::callInner($frame, $inner, 'rewind');
            $state['pos'] = 0;
            while ($pos > $state['pos']) {
                $valid = SplDualIteratorStorage::callInner($frame, $inner, 'valid')->resolveIndirect();
                if (Variable::TYPE_BOOLEAN !== $valid->type || !$valid->toBool()) {
                    break;
                }
                SplDualIteratorStorage::callInner($frame, $inner, 'next');
                ++$state['pos'];
            }
        } else {
            while ($pos > $state['pos']) {
                $valid = SplDualIteratorStorage::callInner($frame, $inner, 'valid')->resolveIndirect();
                if (Variable::TYPE_BOOLEAN !== $valid->type || !$valid->toBool()) {
                    break;
                }
                SplDualIteratorStorage::callInner($frame, $inner, 'next');
                ++$state['pos'];
            }
        }
    }

    /**
     * Seek a SeekableIterator inner without nested VmClassMethod invoke when possible.
     * Native throws from this stack are dispatched from LimitIterator's handler (#24295).
     */
    private static function seekInnerDirect(Frame $frame, ObjectEntry $inner, int $pos): void
    {
        if (SplArrayStorage::hasState($inner)) {
            SplArrayStorage::seekIterator($inner, $pos);

            return;
        }
        $lc = strtolower(ltrim($inner->class->name, '\\'));
        if (DirectoryIteratorBuiltin::CLASS_LC === $lc
            || FilesystemIteratorBuiltin::CLASS_LC === $lc
            || GlobIteratorBuiltin::CLASS_LC === $lc
            || RecursiveDirectoryIteratorBuiltin::CLASS_LC === $lc) {
            DirectoryIteratorBuiltin::seek($inner, $pos);

            return;
        }
        $posVar = new Variable(Variable::TYPE_INTEGER);
        $posVar->int($pos);
        SplDualIteratorStorage::callInnerWithArg($frame, $inner, 'seek', $posVar);
    }

    private static function innerHasSeek(Frame $frame, ObjectEntry $inner): bool
    {
        $entry = $inner->class;
        $classes = $frame->vmContext?->classes ?? [];
        while (true) {
            if (isset($entry->methods['seek'])) {
                return true;
            }
            $parentLc = $entry->parentLc;
            if (null === $parentLc || !isset($classes[$parentLc])) {
                return false;
            }
            $entry = $classes[$parentLc];
        }
    }

    /** @param array{inner: ObjectEntry, offset: int, limit: int, pos: int, rewound: bool} $state */
    private static function relativePos(array $state): int
    {
        return $state['pos'] - $state['offset'];
    }

    /** @return array{inner: ObjectEntry, offset: int, limit: int, pos: int, rewound: bool} */
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
        // php-src zim_LimitIterator___construct — iterator + optional offset/limit (#31071).
        $this->requireUserArgCountRange($frame, 'LimitIterator::__construct', 1, 3);
        $object = SplIteratorSupport::receiver(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::__construct()'
        );
        if (null === $frame->vmContext) {
            throw new \LogicException('LimitIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1],
            'LimitIterator::__construct',
            'Iterator'
        );
        // php-src Z_PARAM_LONG $offset / $count — soft-null DEP+0 outside strict_types (#31621).
        $offset = 0;
        if (isset($frame->calledArgs[2])) {
            $offset = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                2,
                'LimitIterator::__construct',
                2,
                'offset'
            );
            if ($offset < 0) {
                throw new \ValueError(
                    'LimitIterator::__construct(): Argument #2 ($offset) must be greater than or equal to 0'
                );
            }
        }
        $limit = -1;
        if (isset($frame->calledArgs[3])) {
            $limit = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                3,
                'LimitIterator::__construct',
                3,
                'limit'
            );
            if ($limit < -1) {
                throw new \ValueError(
                    'LimitIterator::__construct(): Argument #3 ($limit) must be greater than or equal to -1'
                );
            }
        }
        SplLimitIteratorStorage::init($object, $inner, $offset, $limit);
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

final class LimitIteratorSeek extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('seek');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::seek()'
        );
        // php-src zim_LimitIterator_seek — ZEND_PARSE_PARAMETERS_START(1, 1) Z_PARAM_LONG (#31676).
        $this->requireExactUserArgCount($frame, 'LimitIterator::seek', 1);
        $offset = VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            1,
            'LimitIterator::seek',
            1,
            'offset'
        );
        SplLimitIteratorStorage::seek($frame, $object, $offset);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(SplLimitIteratorStorage::position($object));
        }
    }
}

/**
 * LimitIterator::getPosition() — absolute iterator position (php-src spl_iterators.c; #22264).
 */
final class LimitIteratorGetPosition extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getPosition');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            LimitIteratorBuiltin::CLASS_LC,
            'LimitIterator::getPosition()'
        );
        // php-src zim_LimitIterator_getPosition — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'LimitIterator::getPosition', 0);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(SplLimitIteratorStorage::position($object));
        }
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
        // Inherited zim_IteratorIterator_getInnerIterator — ACE cites IteratorIterator (#30949).
        $this->requireExactUserArgCount($frame, 'IteratorIterator::getInnerIterator', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $inner = SplLimitIteratorStorage::inner($object);
        SplIteratorSupport::ensurePinnedObjectAlive($inner);
        $frame->returnVar->object($inner);
    }
}
