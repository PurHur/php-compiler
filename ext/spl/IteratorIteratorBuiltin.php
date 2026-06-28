<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IteratorIterator / RecursiveIteratorIterator — outer iterator wrappers (php-src ext/spl/spl_iterators.c; #12757).
 */
final class IteratorIteratorBuiltin
{
    public const CLASS_LC = 'iteratoriterator';

    public const LEAVES_ONLY = 0;

    public const SELF_FIRST = 1;

    public const CHILD_FIRST = 2;

    public const CATCH_GET_CHILD = 16;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('IteratorIterator');
        foreach (['OuterIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new IteratorIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        self::registerIteratorMethods($entry, $pub, IteratorIteratorRewind::class);

        $entry->methods['getinneriterator'] = new IteratorIteratorGetInnerIterator();
        $entry->methodVisibility['getinneriterator'] = $pub;
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;

        RecursiveIteratorIteratorBuiltin::registerClass($ctx);
    }

    /** @param class-string<VmClassMethod> $rewindClass */
    public static function registerIteratorMethods(ClassEntry $entry, int $pub, string $rewindClass): void
    {
        foreach ([
            'rewind' => $rewindClass,
            'valid' => IteratorIteratorValid::class,
            'current' => IteratorIteratorCurrent::class,
            'key' => IteratorIteratorKey::class,
            'next' => IteratorIteratorNext::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['valid'], $entry->methods['getinneriterator']);
    }
}

final class RecursiveIteratorIteratorBuiltin
{
    public const CLASS_LC = 'recursiveiteratoriterator';

    public static function registerClass(Context $ctx): void
    {
        IteratorIteratorBuiltin::registerClass($ctx);

        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('RecursiveIteratorIterator');
        $entry->parentLc = IteratorIteratorBuiltin::CLASS_LC;
        foreach (['OuterIterator', 'Traversable', 'Iterator'] as $iface) {
            if (isset($ctx->classes[strtolower($iface)])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new RecursiveIteratorIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        IteratorIteratorBuiltin::registerIteratorMethods($entry, $pub, RecursiveIteratorIteratorRewind::class);

        SplClassConstants::registerIntConstants($entry, [
            'LEAVES_ONLY' => IteratorIteratorBuiltin::LEAVES_ONLY,
            'SELF_FIRST' => IteratorIteratorBuiltin::SELF_FIRST,
            'CHILD_FIRST' => IteratorIteratorBuiltin::CHILD_FIRST,
            'CATCH_GET_CHILD' => IteratorIteratorBuiltin::CATCH_GET_CHILD,
        ]);

        foreach ([
            'getdepth' => RecursiveIteratorIteratorGetDepth::class,
            'setmaxdepth' => RecursiveIteratorIteratorSetMaxDepth::class,
            'getmaxdepth' => RecursiveIteratorIteratorGetMaxDepth::class,
            'getsubiterator' => RecursiveIteratorIteratorGetSubIterator::class,
            'getinneriterator' => RecursiveIteratorIteratorGetInnerIterator::class,
            'callhaschildren' => RecursiveIteratorIteratorCallHasChildren::class,
            'callgetchildren' => RecursiveIteratorIteratorCallGetChildren::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['getdepth'] = 'getDepth';
        $entry->methodNames['setmaxdepth'] = 'setMaxDepth';
        $entry->methodNames['getmaxdepth'] = 'getMaxDepth';
        $entry->methodNames['getsubiterator'] = 'getSubIterator';
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';
        $entry->methodNames['callhaschildren'] = 'callHasChildren';
        $entry->methodNames['callgetchildren'] = 'callGetChildren';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['valid'], $entry->methods['getdepth']);
    }
}

/** @internal */
final class SplDualIteratorStorage
{
    /** @var array<int, array{inner: ObjectEntry, recursive: bool, mode: int, stack: list<ObjectEntry>, maxDepth: int}> */
    private static array $store = [];

    public static function initSimple(ObjectEntry $object, ObjectEntry $inner): void
    {
        self::$store[$object->id] = [
            'inner' => $inner,
            'recursive' => false,
            'mode' => IteratorIteratorBuiltin::LEAVES_ONLY,
            'stack' => [],
            'maxDepth' => -1,
        ];
    }

    public static function initRecursive(ObjectEntry $object, ObjectEntry $inner, int $mode): void
    {
        self::$store[$object->id] = [
            'inner' => $inner,
            'recursive' => true,
            'mode' => $mode,
            'stack' => [],
            'maxDepth' => -1,
        ];
    }

    public static function getDepth(ObjectEntry $object): int
    {
        $stack = self::state($object)['stack'];

        return max(0, \count($stack) - 1);
    }

    public static function setMaxDepth(ObjectEntry $object, int $maxDepth): void
    {
        self::$store[$object->id]['maxDepth'] = $maxDepth;
    }

    /** @return int|false */
    public static function getMaxDepth(ObjectEntry $object): int|false
    {
        $maxDepth = self::state($object)['maxDepth'];

        return $maxDepth < 0 ? false : $maxDepth;
    }

    public static function getSubIterator(ObjectEntry $object, ?int $level): ObjectEntry
    {
        $state = self::state($object);
        if (null === $level) {
            $level = self::getDepth($object);
        }
        if ($level < 0) {
            throw new \OutOfBoundsException('Level must be a non-negative integer');
        }
        if (0 === $level) {
            return $state['inner'];
        }
        $stack = $state['stack'];
        if ($level >= \count($stack)) {
            throw new \OutOfBoundsException('Level '.$level.' not found');
        }

        return $stack[$level];
    }

    public static function callHasChildren(Frame $frame, ObjectEntry $object): bool
    {
        $stack = self::state($object)['stack'];
        if ([] === $stack) {
            return false;
        }
        $top = $stack[\count($stack) - 1];
        $result = self::invokeInner($frame, $top, 'hasChildren')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function callGetChildren(Frame $frame, ObjectEntry $object): ObjectEntry
    {
        $stack = self::state($object)['stack'];
        if ([] === $stack) {
            throw new \RuntimeException('Cannot fetch children on invalid RecursiveIteratorIterator position');
        }
        $top = $stack[\count($stack) - 1];

        return self::getChildren($frame, $top);
    }

    public static function inner(ObjectEntry $object): ObjectEntry
    {
        return self::state($object)['inner'];
    }

    /** @return list<ObjectEntry> Active iterator stack for recursive wrappers (#13223). */
    public static function iteratorStack(ObjectEntry $object): array
    {
        return self::state($object)['stack'];
    }

    public static function rewindSimple(Frame $frame, ObjectEntry $object): void
    {
        self::invokeInner($frame, self::inner($object), 'rewind');
    }

    public static function validSimple(Frame $frame, ObjectEntry $object): bool
    {
        $result = self::invokeInner($frame, self::inner($object), 'valid')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function currentSimple(Frame $frame, ObjectEntry $object): Variable
    {
        return self::invokeInner($frame, self::inner($object), 'current');
    }

    public static function keySimple(Frame $frame, ObjectEntry $object): Variable
    {
        return self::invokeInner($frame, self::inner($object), 'key');
    }

    public static function nextSimple(Frame $frame, ObjectEntry $object): void
    {
        self::invokeInner($frame, self::inner($object), 'next');
    }

    public static function rewindRecursive(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        $state['stack'] = [];
        self::invokeInner($frame, $state['inner'], 'rewind');
        $state['stack'][] = $state['inner'];
        self::nextElement($frame, $object);
    }

    public static function validRecursive(Frame $frame, ObjectEntry $object): bool
    {
        $stack = self::state($object)['stack'];
        if ([] === $stack) {
            return false;
        }
        $top = $stack[\count($stack) - 1];

        return self::invokeInner($frame, $top, 'valid')->resolveIndirect()->toBool();
    }

    public static function currentRecursive(Frame $frame, ObjectEntry $object): Variable
    {
        $stack = self::state($object)['stack'];
        if ([] === $stack) {
            throw new \RuntimeException('Cannot fetch current() on invalid RecursiveIteratorIterator position');
        }

        return self::invokeInner($frame, $stack[\count($stack) - 1], 'current');
    }

    public static function keyRecursive(Frame $frame, ObjectEntry $object): Variable
    {
        $stack = self::state($object)['stack'];
        if ([] === $stack) {
            throw new \RuntimeException('Cannot fetch key() on invalid RecursiveIteratorIterator position');
        }

        return self::invokeInner($frame, $stack[\count($stack) - 1], 'key');
    }

    public static function nextRecursive(Frame $frame, ObjectEntry $object): void
    {
        $stack = &self::$store[$object->id]['stack'];
        if ([] === $stack) {
            return;
        }
        $top = $stack[\count($stack) - 1];
        self::invokeInner($frame, $top, 'next');
        self::nextElement($frame, $object);
    }

    public static function resolveIterator(Context $ctx, Frame $frame, Variable $traversable): ObjectEntry
    {
        $resolved = $traversable->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(
                'IteratorIterator::__construct(): Argument #1 ($iterator) must be of type Traversable, '
                .self::typeLabel($resolved).' given'
            );
        }
        $object = $resolved->toObject();
        if (InterfaceCheck::entryImplements($object->class, 'iteratoraggregate', $ctx)) {
            $inner = self::vm($frame)->invokeInstanceMethod($object, 'getIterator')->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $inner->type) {
                throw new \UnexpectedValueException('IteratorAggregate::getIterator() must return an object');
            }
            $object = $inner->toObject();
        }
        if (!InterfaceCheck::entryImplements($object->class, 'iterator', $ctx)) {
            throw new \InvalidArgumentException('An instance of an iterator cannot be used with IteratorIterator');
        }

        return $object;
    }

    public static function resolveRecursiveIterator(Context $ctx, Frame $frame, Variable $traversable): ObjectEntry
    {
        $object = self::resolveIterator($ctx, $frame, $traversable);
        if (!InterfaceCheck::entryImplements($object->class, 'recursiveiterator', $ctx)) {
            throw new \InvalidArgumentException(
                'An instance of RecursiveIterator or IteratorAggregate creating it is required'
            );
        }

        return $object;
    }

    /** @return array{inner: ObjectEntry, recursive: bool, mode: int, stack: list<ObjectEntry>, maxDepth: int} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('Iterator wrapper state missing');
        }

        return self::$store[$object->id];
    }

    private static function nextElement(Frame $frame, ObjectEntry $object): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('Iterator wrapper requires VM context');
        }
        $state = &self::$store[$object->id];
        while ([] !== $state['stack']) {
            $top = $state['stack'][\count($state['stack']) - 1];
            $valid = self::invokeInner($frame, $top, 'valid')->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $valid->type || !$valid->toBool()) {
                \array_pop($state['stack']);
                if ([] !== $state['stack']) {
                    $parent = $state['stack'][\count($state['stack']) - 1];
                    self::invokeInner($frame, $parent, 'next');
                }
                continue;
            }
            if (self::mustSkipForMaxDepth($frame->vmContext, $frame, $object, $top)) {
                self::invokeInner($frame, $top, 'next');
                continue;
            }
            if (self::shouldRecurse($frame->vmContext, $frame, $object, $top, $state['mode'])) {
                $child = self::getChildren($frame, $top);
                self::invokeInner($frame, $child, 'rewind');
                $state['stack'][] = $child;
                continue;
            }

            return;
        }
    }

    private static function mustSkipForMaxDepth(Context $ctx, Frame $frame, ObjectEntry $wrapper, ObjectEntry $iterator): bool
    {
        $wrapperState = self::state($wrapper);
        if ($wrapperState['maxDepth'] < 0) {
            return false;
        }
        $depth = max(0, \count($wrapperState['stack']) - 1);
        if ($depth < $wrapperState['maxDepth']) {
            return false;
        }
        if (!InterfaceCheck::entryImplements($iterator->class, 'recursiveiterator', $ctx)) {
            return false;
        }
        $result = self::invokeInner($frame, $iterator, 'hasChildren')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    private static function shouldRecurse(Context $ctx, Frame $frame, ObjectEntry $wrapper, ObjectEntry $iterator, int $mode): bool
    {
        if (IteratorIteratorBuiltin::LEAVES_ONLY !== $mode) {
            return false;
        }
        if (!InterfaceCheck::entryImplements($iterator->class, 'recursiveiterator', $ctx)) {
            return false;
        }
        $wrapperState = self::state($wrapper);
        $depth = max(0, \count($wrapperState['stack']) - 1);
        if ($wrapperState['maxDepth'] >= 0 && $depth >= $wrapperState['maxDepth']) {
            return false;
        }
        $result = self::invokeInner($frame, $iterator, 'hasChildren')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    private static function getChildren(Frame $frame, ObjectEntry $iterator): ObjectEntry
    {
        $result = self::invokeInner($frame, $iterator, 'getChildren')->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $result->type) {
            throw new \UnexpectedValueException('RecursiveIterator::getChildren() must return an object');
        }

        return $result->toObject();
    }

    private static function invokeInner(Frame $frame, ObjectEntry $inner, string $method): Variable
    {
        return self::callInner($frame, $inner, $method);
    }

    /** @internal Shared by LimitIterator (#12893). */
    public static function callInner(Frame $frame, ObjectEntry $inner, string $method): Variable
    {
        return self::vm($frame)->invokeInstanceMethod($inner, $method);
    }

    private static function vm(Frame $frame): \PHPCompiler\VM
    {
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            throw new \LogicException('Iterator wrapper requires VM runtime');
        }

        return $frame->vmContext->runtime->vm;
    }

    private static function typeLabel(Variable $var): string
    {
        return match ($var->type) {
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}

final class IteratorIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            IteratorIteratorBuiltin::CLASS_LC,
            'IteratorIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'IteratorIterator::__construct() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('IteratorIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        SplDualIteratorStorage::initSimple($object, $inner);
    }
}

final class RecursiveIteratorIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::__construct()'
        );
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'RecursiveIteratorIterator::__construct() expects at least 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveIteratorIterator::__construct() requires VM context');
        }
        $inner = SplDualIteratorStorage::resolveRecursiveIterator(
            $frame->vmContext,
            $frame,
            $frame->calledArgs[1]
        );
        $mode = IteratorIteratorBuiltin::LEAVES_ONLY;
        if (isset($frame->calledArgs[2])) {
            $modeArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $modeArg->type) {
                $mode = $modeArg->toInt();
            }
        }
        SplDualIteratorStorage::initRecursive($object, $inner, $mode);
    }
}

final class IteratorIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            IteratorIteratorBuiltin::CLASS_LC,
            'IteratorIterator::rewind()'
        );
        SplDualIteratorStorage::rewindSimple($frame, $object);
    }
}

final class RecursiveIteratorIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::rewind()'
        );
        SplDualIteratorStorage::rewindRecursive($frame, $object);
    }
}

final class IteratorIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            IteratorIteratorBuiltin::CLASS_LC,
            'IteratorIterator::valid()'
        );
        $valid = strcasecmp($object->class->name, 'RecursiveIteratorIterator') === 0
            ? SplDualIteratorStorage::validRecursive($frame, $object)
            : SplDualIteratorStorage::validSimple($frame, $object);
        SplIteratorSupport::setReturnBool($frame, $valid);
    }
}

final class IteratorIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            IteratorIteratorBuiltin::CLASS_LC,
            'IteratorIterator::current()'
        );
        $current = strcasecmp($object->class->name, 'RecursiveIteratorIterator') === 0
            ? SplDualIteratorStorage::currentRecursive($frame, $object)
            : SplDualIteratorStorage::currentSimple($frame, $object);
        SplIteratorSupport::copyReturnFrom($frame, $current);
    }
}

final class IteratorIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            IteratorIteratorBuiltin::CLASS_LC,
            'IteratorIterator::key()'
        );
        $key = strcasecmp($object->class->name, 'RecursiveIteratorIterator') === 0
            ? SplDualIteratorStorage::keyRecursive($frame, $object)
            : SplDualIteratorStorage::keySimple($frame, $object);
        SplIteratorSupport::copyReturnFrom($frame, $key);
    }
}

final class IteratorIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            IteratorIteratorBuiltin::CLASS_LC,
            'IteratorIterator::next()'
        );
        if (strcasecmp($object->class->name, 'RecursiveIteratorIterator') === 0) {
            SplDualIteratorStorage::nextRecursive($frame, $object);
        } else {
            SplDualIteratorStorage::nextSimple($frame, $object);
        }
    }
}

final class IteratorIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            IteratorIteratorBuiltin::CLASS_LC,
            'IteratorIterator::getInnerIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $inner = SplDualIteratorStorage::inner($object);
        $frame->returnVar->object($inner);
    }
}

final class RecursiveIteratorIteratorGetDepth extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDepth');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::getDepth()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplDualIteratorStorage::getDepth($object));
    }
}

final class RecursiveIteratorIteratorSetMaxDepth extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setMaxDepth');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::setMaxDepth()'
        );
        $maxDepth = -1;
        if (isset($frame->calledArgs[1])) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $arg->type) {
                $maxDepth = $arg->toInt();
            }
        }
        SplDualIteratorStorage::setMaxDepth($object, $maxDepth);
    }
}

final class RecursiveIteratorIteratorGetMaxDepth extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getMaxDepth');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::getMaxDepth()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $maxDepth = SplDualIteratorStorage::getMaxDepth($object);
        if (false === $maxDepth) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($maxDepth);
        }
    }
}

final class RecursiveIteratorIteratorGetSubIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSubIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::getSubIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $level = null;
        if (isset($frame->calledArgs[1])) {
            $arg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $level = $arg->toInt();
            }
        }
        $inner = SplDualIteratorStorage::getSubIterator($object, $level);
        $frame->returnVar->object($inner);
    }
}

final class RecursiveIteratorIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::getInnerIterator()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $inner = SplDualIteratorStorage::inner($object);
        $frame->returnVar->object($inner);
    }
}

final class RecursiveIteratorIteratorCallHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('callHasChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::callHasChildren()'
        );
        SplIteratorSupport::setReturnBool(
            $frame,
            SplDualIteratorStorage::callHasChildren($frame, $object)
        );
    }
}

final class RecursiveIteratorIteratorCallGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('callGetChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::callGetChildren()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $child = SplDualIteratorStorage::callGetChildren($frame, $object);
        $frame->returnVar->object($child);
    }
}
