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

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['rewind'], $entry->methods['valid']);
    }
}

/** @internal */
final class SplDualIteratorStorage
{
    /** @var array<int, array{inner: ObjectEntry, recursive: bool, mode: int, stack: list<ObjectEntry>}> */
    private static array $store = [];

    public static function initSimple(ObjectEntry $object, ObjectEntry $inner): void
    {
        self::$store[$object->id] = [
            'inner' => $inner,
            'recursive' => false,
            'mode' => IteratorIteratorBuiltin::LEAVES_ONLY,
            'stack' => [],
        ];
    }

    public static function initRecursive(ObjectEntry $object, ObjectEntry $inner, int $mode): void
    {
        self::$store[$object->id] = [
            'inner' => $inner,
            'recursive' => true,
            'mode' => $mode,
            'stack' => [],
        ];
    }

    public static function inner(ObjectEntry $object): ObjectEntry
    {
        return self::state($object)['inner'];
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

    /** @return array{inner: ObjectEntry, recursive: bool, mode: int, stack: list<ObjectEntry>} */
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
            if (self::shouldRecurse($frame->vmContext, $frame, $top, $state['mode'])) {
                $child = self::getChildren($frame, $top);
                self::invokeInner($frame, $child, 'rewind');
                $state['stack'][] = $child;
                continue;
            }

            return;
        }
    }

    private static function shouldRecurse(Context $ctx, Frame $frame, ObjectEntry $iterator, int $mode): bool
    {
        if (IteratorIteratorBuiltin::LEAVES_ONLY !== $mode) {
            return false;
        }
        if (!InterfaceCheck::entryImplements($iterator->class, 'recursiveiterator', $ctx)) {
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
