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
        // php-src spl_dual_it_call_method — forward unknown methods to the inner iterator (#24287).
        $entry->methods['__call'] = new IteratorIteratorCall();
        $entry->methodVisibility['__call'] = $pub;
        $entry->methodNames['__call'] = '__call';

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
        return isset(
            $entry->methods['rewind'],
            $entry->methods['valid'],
            $entry->methods['getinneriterator'],
            $entry->methods['__call']
        );
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
            // php-src traversal hooks — empty on base; subclasses override (#20146).
            'beginiteration' => RecursiveIteratorIteratorBeginIteration::class,
            'enditeration' => RecursiveIteratorIteratorEndIteration::class,
            'beginchildren' => RecursiveIteratorIteratorBeginChildren::class,
            'endchildren' => RecursiveIteratorIteratorEndChildren::class,
            'nextelement' => RecursiveIteratorIteratorNextElement::class,
            // php-src spl_recursive_it_get_method forwards unknown methods to the current
            // sub-iterator; register the RDI path APIs explicitly (#24314).
            'getsubpath' => RecursiveIteratorIteratorGetSubPath::class,
            'getsubpathname' => RecursiveIteratorIteratorGetSubPathname::class,
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
        $entry->methodNames['beginiteration'] = 'beginIteration';
        $entry->methodNames['enditeration'] = 'endIteration';
        $entry->methodNames['beginchildren'] = 'beginChildren';
        $entry->methodNames['endchildren'] = 'endChildren';
        $entry->methodNames['nextelement'] = 'nextElement';
        $entry->methodNames['getsubpath'] = 'getSubPath';
        $entry->methodNames['getsubpathname'] = 'getSubPathname';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset(
            $entry->methods['rewind'],
            $entry->methods['valid'],
            $entry->methods['getdepth'],
            $entry->methods['beginchildren'],
            $entry->methods['nextelement']
        );
    }
}

/** @internal */
final class SplDualIteratorStorage
{
    private const RS_START = 0;

    private const RS_TEST = 1;

    private const RS_SELF = 2;

    private const RS_CHILD = 3;

    private const RS_NEXT = 4;

    /** @var array<int, array{inner: ObjectEntry, recursive: bool, mode: int, flags: int, stack: list<array{iterator: ObjectEntry, state: int}>, maxDepth: int, rewound: bool, noRewind: bool, inIteration: bool, innerPinKey: string}> */
    private static array $store = [];

    public static function hasStateFor(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]);
    }

    /** True when wrapper uses recursive walk (RII / RecursiveTreeIterator / subclasses). */
    public static function usesRecursiveWalk(ObjectEntry $object): bool
    {
        return isset(self::$store[$object->id]) && self::$store[$object->id]['recursive'];
    }

    /**
     * Move wrapper sidecar when ClassConstMaterializer detaches object identity (#17721).
     */
    public static function transferState(int $fromId, int $toId): void
    {
        if (!isset(self::$store[$fromId])) {
            return;
        }
        self::$store[$toId] = self::$store[$fromId];
        unset(self::$store[$fromId]);
    }

    public static function initSimple(ObjectEntry $object, ObjectEntry $inner): void
    {
        $pinKey = 'dual:'.$object->id.':inner';
        self::replaceStore($object->id, [
            'inner' => SplIteratorSupport::pinObject($inner, $pinKey),
            'recursive' => false,
            'mode' => IteratorIteratorBuiltin::LEAVES_ONLY,
            'flags' => 0,
            'stack' => [],
            'maxDepth' => -1,
            'rewound' => false,
            'noRewind' => false,
            'inIteration' => false,
            'innerPinKey' => $pinKey,
        ]);
    }

    /** NoRewindIterator — valid/current without outer rewind(); inner position preserved (#15150). */
    public static function initNoRewind(ObjectEntry $object, ObjectEntry $inner): void
    {
        $pinKey = 'dual:'.$object->id.':inner';
        self::replaceStore($object->id, [
            'inner' => SplIteratorSupport::pinObject($inner, $pinKey),
            'recursive' => false,
            'mode' => IteratorIteratorBuiltin::LEAVES_ONLY,
            'flags' => 0,
            'stack' => [],
            'maxDepth' => -1,
            'rewound' => true,
            'noRewind' => true,
            'inIteration' => false,
            'innerPinKey' => $pinKey,
        ]);
    }

    public static function initRecursive(ObjectEntry $object, ObjectEntry $inner, int $mode, int $flags = 0): void
    {
        // php-src spl_recursive_it_it_construct — inner iterator on stack at RS_START (#16904).
        // Pin once: stack[0] aliases the same ObjectEntry as inner (#6138).
        // mode and flags are separate (php-src intern->mode / intern->flags); do not OR
        // CATCH_GET_CHILD into mode — but if callers pass it as mode (common misuse), the
        // advance switch must leave unknown modes unmatched so parents yield (#24293).
        $pinKey = 'dual:'.$object->id.':inner';
        $pinned = SplIteratorSupport::pinObject($inner, $pinKey);
        self::replaceStore($object->id, [
            'inner' => $pinned,
            'recursive' => true,
            'mode' => $mode,
            'flags' => $flags,
            'stack' => [
                ['iterator' => $pinned, 'state' => self::RS_START],
            ],
            'maxDepth' => -1,
            'rewound' => false,
            'noRewind' => false,
            'inIteration' => false,
            'innerPinKey' => $pinKey,
        ]);
    }

    /**
     * @param array{inner: ObjectEntry, recursive: bool, mode: int, flags: int, stack: list<array{iterator: ObjectEntry, state: int}>, maxDepth: int, rewound: bool, noRewind: bool, inIteration: bool, innerPinKey: string} $state
     */
    private static function replaceStore(int $objectId, array $state): void
    {
        if (isset(self::$store[$objectId])) {
            self::releasePinnedIterators(self::$store[$objectId]);
        }
        self::$store[$objectId] = $state;
    }

    /**
     * @param array{inner: ObjectEntry, stack: list<array{iterator: ObjectEntry, state: int}>, innerPinKey?: string} $state
     */
    private static function releasePinnedIterators(array $state): void
    {
        $innerKey = $state['innerPinKey'] ?? ('obj:'.$state['inner']->id);
        SplIteratorSupport::unpinObject($state['inner'], $innerKey);
        $released = [$state['inner']->id => true];
        foreach ($state['stack'] as $index => $frame) {
            $id = $frame['iterator']->id;
            if (isset($released[$id])) {
                continue;
            }
            SplIteratorSupport::unpinObject($frame['iterator'], 'dual-stack:'.$id.':'.$index);
            $released[$id] = true;
        }
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

    /**
     * php-src SPL_METHOD(RecursiveIteratorIterator, getSubIterator) — NULL for missing /
     * negative levels (no OutOfBoundsException) (#24315).
     */
    public static function getSubIterator(ObjectEntry $object, ?int $level): ?ObjectEntry
    {
        $state = self::state($object);
        if (null === $level) {
            $level = self::getDepth($object);
        }
        if ($level < 0) {
            return null;
        }
        if (0 === $level) {
            return $state['inner'];
        }
        $stack = $state['stack'];
        if ($level >= \count($stack)) {
            return null;
        }

        return $stack[$level]['iterator'];
    }

    /**
     * php-src spl_recursive_it_get_method — forward getSubPath / getSubPathname to the
     * current sub-iterator (RecursiveDirectoryIterator) (#24314).
     */
    public static function callCurrentSubMethod(Frame $frame, ObjectEntry $object, string $method): Variable
    {
        $sub = self::getSubIterator($object, null);
        if (null === $sub) {
            throw new \Error('Call to undefined method RecursiveIteratorIterator::'.$method.'()');
        }

        return self::invokeInner($frame, $sub, $method);
    }

    public static function callHasChildren(Frame $frame, ObjectEntry $object): bool
    {
        $top = self::stackTop($object);
        if (null === $top) {
            return false;
        }
        $result = self::invokeInner($frame, $top, 'hasChildren')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function callGetChildren(Frame $frame, ObjectEntry $object): ObjectEntry
    {
        $top = self::stackTop($object);
        if (null === $top) {
            throw new \RuntimeException('Cannot fetch children on invalid RecursiveIteratorIterator position');
        }

        return self::getChildren($frame, $top);
    }

    public static function inner(ObjectEntry $object): ObjectEntry
    {
        return self::state($object)['inner'];
    }

    /** @return list<ObjectEntry> Active iterator stack for recursive wrappers (#13223). */
    public static function iteratorStack(ObjectEntry $object): array
    {
        return array_map(
            static fn (array $frame): ObjectEntry => $frame['iterator'],
            self::state($object)['stack']
        );
    }

    public static function rewindSimple(Frame $frame, ObjectEntry $object): void
    {
        self::$store[$object->id]['rewound'] = true;
        self::invokeInner($frame, self::inner($object), 'rewind');
    }

    public static function validSimple(Frame $frame, ObjectEntry $object): bool
    {
        if (!self::isPositionValid($object)) {
            return false;
        }
        $result = self::invokeInner($frame, self::inner($object), 'valid')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    public static function currentSimple(Frame $frame, ObjectEntry $object): Variable
    {
        // php-src dual_it proxies current only while inner valid (#24272 FilterIterator;
        // same for IteratorIterator / ParentIterator when no accepted element).
        if (!self::validSimple($frame, $object)) {
            return self::nullVariable();
        }

        return self::invokeInner($frame, self::inner($object), 'current');
    }

    public static function keySimple(Frame $frame, ObjectEntry $object): Variable
    {
        if (!self::validSimple($frame, $object)) {
            return self::nullVariable();
        }

        return self::invokeInner($frame, self::inner($object), 'key');
    }

    public static function nextSimple(Frame $frame, ObjectEntry $object): void
    {
        self::invokeInner($frame, self::inner($object), 'next');
    }

    public static function rewindRecursive(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        $state['rewound'] = true;
        // php-src spl_recursive_it_rewind_ex — endChildren while unwinding nested levels (#20146).
        while (\count($state['stack']) > 1) {
            self::callTraversalHook($frame, $object, 'endChildren');
            $popIndex = \count($state['stack']) - 1;
            $popped = \array_pop($state['stack']);
            if (null !== $popped && $popped['iterator']->id !== $state['inner']->id) {
                SplIteratorSupport::unpinObject(
                    $popped['iterator'],
                    'dual-stack:'.$popped['iterator']->id.':'.$popIndex
                );
            }
        }
        self::clearStackKeepingInner($object->id);
        $state['stack'] = [
            ['iterator' => $state['inner'], 'state' => self::RS_START],
        ];
        self::invokeInner($frame, $state['inner'], 'rewind');
        // php-src: beginIteration only when not already in iteration.
        if (!$state['inIteration']) {
            self::callTraversalHook($frame, $object, 'beginIteration');
        }
        $state['inIteration'] = true;
        self::advanceToYield($frame, $object);
    }

    private static function clearStackKeepingInner(int $objectId): void
    {
        $state = &self::$store[$objectId];
        $innerId = $state['inner']->id;
        foreach ($state['stack'] as $index => $frame) {
            if ($frame['iterator']->id === $innerId) {
                continue;
            }
            SplIteratorSupport::unpinObject($frame['iterator'], 'dual-stack:'.$frame['iterator']->id.':'.$index);
        }
        $state['stack'] = [];
    }

    public static function validRecursive(Frame $frame, ObjectEntry $object): bool
    {
        $top = self::stackTop($object);
        if (null === $top || !self::isIteratorValid($frame, $top)) {
            $state = &self::$store[$object->id];
            // php-src spl_recursive_it_valid_ex — endIteration when valid first fails (#20146).
            if ($state['inIteration']) {
                self::callTraversalHook($frame, $object, 'endIteration');
                $state['inIteration'] = false;
            }

            return false;
        }

        return true;
    }

    public static function currentRecursive(Frame $frame, ObjectEntry $object): Variable
    {
        $top = self::stackTop($object);
        if (null === $top) {
            throw new \RuntimeException('Cannot fetch current() on invalid RecursiveIteratorIterator position');
        }

        return self::invokeInner($frame, $top, 'current');
    }

    public static function keyRecursive(Frame $frame, ObjectEntry $object): Variable
    {
        $top = self::stackTop($object);
        if (null === $top) {
            throw new \RuntimeException('Cannot fetch key() on invalid RecursiveIteratorIterator position');
        }

        return self::invokeInner($frame, $top, 'key');
    }

    public static function nextRecursive(Frame $frame, ObjectEntry $object): void
    {
        if ([] === self::state($object)['stack']) {
            return;
        }
        self::advanceFromYield($frame, $object);
        self::advanceToYield($frame, $object);
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

    /** @return array{inner: ObjectEntry, recursive: bool, mode: int, flags: int, stack: list<array{iterator: ObjectEntry, state: int}>, maxDepth: int, rewound: bool} */
    private static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('Iterator wrapper state missing');
        }

        return self::$store[$object->id];
    }

    /** php-src spl_iterators_iterator_current — outer position invalid until rewind() (#14687). */
    private static function isPositionValid(ObjectEntry $object): bool
    {
        $state = self::state($object);
        if ($state['recursive']) {
            return [] !== $state['stack'];
        }
        if ($state['noRewind']) {
            return true;
        }

        return $state['rewound'];
    }

    private static function nullVariable(): Variable
    {
        $null = new Variable();
        $null->null();

        return $null;
    }

    private static function advanceFromYield(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        if ([] === $state['stack']) {
            return;
        }
        $level = \count($state['stack']) - 1;
        $entry = &$state['stack'][$level];
        if (self::RS_CHILD === $entry['state']) {
            self::descendIntoChildren($frame, $object, $level);

            return;
        }
        $entry['state'] = self::RS_NEXT;
    }

    private static function advanceToYield(Frame $frame, ObjectEntry $object): void
    {
        $state = &self::$store[$object->id];
        while ([] !== $state['stack']) {
            $level = \count($state['stack']) - 1;
            $entry = &$state['stack'][$level];
            $iterator = $entry['iterator'];
            switch ($entry['state']) {
                case self::RS_START:
                case self::RS_NEXT:
                    if (self::RS_NEXT === $entry['state']) {
                        self::invokeInner($frame, $iterator, 'next');
                    }
                    if (!self::isIteratorValid($frame, $iterator)) {
                        // php-src: endChildren before pop while depth still at child level (#20146).
                        if (\count($state['stack']) > 1) {
                            self::callTraversalHook($frame, $object, 'endChildren');
                        }
                        $popIndex = \count($state['stack']) - 1;
                        $popped = \array_pop($state['stack']);
                        if (null !== $popped && $popped['iterator']->id !== $state['inner']->id) {
                            SplIteratorSupport::unpinObject(
                                $popped['iterator'],
                                'dual-stack:'.$popped['iterator']->id.':'.$popIndex
                            );
                        }
                        if ([] !== $state['stack']) {
                            $parentLevel = \count($state['stack']) - 1;
                            if (self::RS_SELF !== $state['stack'][$parentLevel]['state']) {
                                $state['stack'][$parentLevel]['state'] = self::RS_NEXT;
                            }
                        }
                        continue 2;
                    }
                    $entry['state'] = self::RS_TEST;
                    // fall through
                case self::RS_TEST:
                    if (null !== $frame->vmContext
                        && self::mustSkipForMaxDepth($frame->vmContext, $frame, $object, $iterator)) {
                        $entry['state'] = self::RS_NEXT;
                        continue 2;
                    }
                    // php-src spl_recursive_it_move_forward_ex — switch(object->mode) with no
                    // default: unknown modes (e.g. CATCH_GET_CHILD OR'd into mode = 16) fall
                    // through and yield the current element without descending (#24293).
                    $mode = $state['mode'];
                    $hasChildren = self::iteratorHasChildren($frame, $object, $iterator, $level, $state);
                    if ($hasChildren) {
                        if (self::canDescend($state, $level)) {
                            if (IteratorIteratorBuiltin::LEAVES_ONLY === $mode
                                || IteratorIteratorBuiltin::CHILD_FIRST === $mode) {
                                self::descendIntoChildren($frame, $object, $level);
                                continue 2;
                            }
                            if (IteratorIteratorBuiltin::SELF_FIRST === $mode) {
                                $entry['state'] = self::RS_CHILD;
                                self::callTraversalHook($frame, $object, 'nextElement');

                                return;
                            }
                            // Unknown mode: yield without descending (php-src switch fallthrough).
                        } elseif (IteratorIteratorBuiltin::LEAVES_ONLY === $mode) {
                            $entry['state'] = self::RS_NEXT;
                            continue 2;
                        }
                    }
                    $entry['state'] = self::RS_NEXT;
                    self::callTraversalHook($frame, $object, 'nextElement');

                    return;
                case self::RS_SELF:
                    self::callTraversalHook($frame, $object, 'nextElement');
                    if (IteratorIteratorBuiltin::SELF_FIRST === $state['mode']) {
                        $entry['state'] = self::RS_CHILD;
                    } else {
                        $entry['state'] = self::RS_NEXT;
                    }

                    return;
                case self::RS_CHILD:
                    self::descendIntoChildren($frame, $object, $level);
                    continue 2;
            }
        }
    }

    private static function descendIntoChildren(Frame $frame, ObjectEntry $object, int $level): void
    {
        $state = &self::$store[$object->id];
        $entry = &$state['stack'][$level];
        $child = self::tryGetChildren($frame, $object, $entry['iterator']);
        if (null === $child) {
            // CATCH_GET_CHILD: skip this element after getChildren threw (#24293 / php-src).
            $entry['state'] = self::RS_NEXT;

            return;
        }
        $stackIndex = \count($state['stack']);
        SplIteratorSupport::pinObject($child, 'dual-stack:'.$child->id.':'.$stackIndex);
        self::invokeInner($frame, $child, 'rewind');
        if (IteratorIteratorBuiltin::CHILD_FIRST === $state['mode']) {
            $entry['state'] = self::RS_SELF;
        } else {
            $entry['state'] = self::RS_NEXT;
        }
        $state['stack'][] = ['iterator' => $child, 'state' => self::RS_START];
        // php-src: beginChildren after child is on the stack (#20146).
        self::callTraversalHook($frame, $object, 'beginChildren');
    }

    /** Invoke RII/RTI traversal hook (base stubs are no-ops; subclasses override). */
    private static function callTraversalHook(Frame $frame, ObjectEntry $object, string $method): void
    {
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            return;
        }
        $vm = $frame->vmContext->runtime->vm;
        if (!$vm->hasInstanceMethod($object->class, strtolower($method))) {
            return;
        }
        $vm->invokeInstanceMethod($object, $method);
    }

    private static function catchesGetChild(ObjectEntry $object): bool
    {
        return 0 !== (self::state($object)['flags'] & IteratorIteratorBuiltin::CATCH_GET_CHILD);
    }

    private static function stackTop(ObjectEntry $object): ?ObjectEntry
    {
        $stack = self::state($object)['stack'];
        if ([] === $stack) {
            return null;
        }

        return $stack[\count($stack) - 1]['iterator'];
    }

    private static function isIteratorValid(Frame $frame, ObjectEntry $iterator): bool
    {
        $valid = self::invokeInner($frame, $iterator, 'valid')->resolveIndirect();

        return Variable::TYPE_BOOLEAN === $valid->type && $valid->toBool();
    }

    /** @param array{inner: ObjectEntry, recursive: bool, mode: int, flags: int, stack: list<array{iterator: ObjectEntry, state: int}>, maxDepth: int} $state */
    private static function iteratorHasChildren(
        Frame $frame,
        ObjectEntry $object,
        ObjectEntry $iterator,
        int $level,
        array $state
    ): bool {
        if (null === $frame->vmContext) {
            return false;
        }
        if (!InterfaceCheck::entryImplements($iterator->class, 'recursiveiterator', $frame->vmContext)) {
            return false;
        }
        try {
            $result = self::invokeInner($frame, $iterator, 'hasChildren')->resolveIndirect();
        } catch (\Throwable $e) {
            if (self::catchesGetChild($object)) {
                // php-src: clear exception; treat as no-children path → yield current.
                return false;
            }
            throw $e;
        }

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    /** @param array{inner: ObjectEntry, recursive: bool, mode: int, flags: int, stack: list<array{iterator: ObjectEntry, state: int}>, maxDepth: int} $state */
    private static function canDescend(array $state, int $level): bool
    {
        if ($state['maxDepth'] < 0) {
            return true;
        }

        return $level < $state['maxDepth'];
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
        try {
            $result = self::invokeInner($frame, $iterator, 'hasChildren')->resolveIndirect();
        } catch (\Throwable $e) {
            if (self::catchesGetChild($wrapper)) {
                return false;
            }
            throw $e;
        }

        return Variable::TYPE_BOOLEAN === $result->type && $result->toBool();
    }

    /**
     * @return ObjectEntry|null null when CATCH_GET_CHILD swallowed a getChildren failure
     */
    private static function tryGetChildren(Frame $frame, ObjectEntry $wrapper, ObjectEntry $iterator): ?ObjectEntry
    {
        try {
            return self::getChildren($frame, $iterator);
        } catch (\Throwable $e) {
            if (self::catchesGetChild($wrapper)) {
                return null;
            }
            throw $e;
        }
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
        SplIteratorSupport::ensurePinnedObjectAlive($inner);

        return self::vm($frame)->invokeInstanceMethod($inner, $method);
    }

    /** @internal Shared by LimitIterator (#12893, #13963). */
    public static function callInnerWithArg(Frame $frame, ObjectEntry $inner, string $method, Variable $arg): Variable
    {
        SplIteratorSupport::ensurePinnedObjectAlive($inner);

        return self::vm($frame)->invokeInstanceMethod($inner, $method, $arg);
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
        $object = SplIteratorSupport::receiverIsA(
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
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            $modeArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $modeArg->type) {
                $mode = $modeArg->toInt();
            }
        }
        if (isset($frame->calledArgs[3])) {
            $flagsArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $flagsArg->type) {
                $flags = $flagsArg->toInt();
            }
        }
        SplDualIteratorStorage::initRecursive($object, $inner, $mode, $flags);
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
        // php-src zim_IteratorIterator_rewind — ZEND_PARSE_PARAMETERS_NONE (#31010; shared with RII).
        $this->requireExactUserArgCount($frame, $object->class->name.'::rewind', 0);
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
        // php-src zim_RecursiveIteratorIterator_rewind — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::rewind', 0);
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
        // php-src dual-it valid — ZEND_PARSE_PARAMETERS_NONE; cite runtime class (#31010).
        $this->requireExactUserArgCount($frame, $object->class->name.'::valid', 0);
        $valid = SplDualIteratorStorage::usesRecursiveWalk($object)
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
        // php-src dual-it current — ZEND_PARSE_PARAMETERS_NONE; cite runtime class (#31010).
        $this->requireExactUserArgCount($frame, $object->class->name.'::current', 0);
        $current = SplDualIteratorStorage::usesRecursiveWalk($object)
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
        // php-src dual-it key — ZEND_PARSE_PARAMETERS_NONE; cite runtime class (#31010).
        $this->requireExactUserArgCount($frame, $object->class->name.'::key', 0);
        $key = SplDualIteratorStorage::usesRecursiveWalk($object)
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
        // php-src dual-it next — ZEND_PARSE_PARAMETERS_NONE; cite runtime class (#31010).
        $this->requireExactUserArgCount($frame, $object->class->name.'::next', 0);
        if (SplDualIteratorStorage::usesRecursiveWalk($object)) {
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
        // php-src zim_IteratorIterator_getInnerIterator — ZEND_PARSE_PARAMETERS_NONE (#30949).
        $this->requireExactUserArgCount($frame, 'IteratorIterator::getInnerIterator', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $inner = SplDualIteratorStorage::inner($object);
        SplIteratorSupport::ensurePinnedObjectAlive($inner);
        $frame->returnVar->object($inner);
    }
}

/**
 * php-src spl_dual_it_call_method — forward unknown methods to the inner iterator (#24287).
 * Undefined methods report the inner class name (Zend get_method on the wrapped object).
 */
final class IteratorIteratorCall extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__call');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            IteratorIteratorBuiltin::CLASS_LC,
            'IteratorIterator::__call()'
        );
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'IteratorIterator::__call() expects exactly 2 arguments, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
        $method = $frame->calledArgs[1]->resolveIndirect()->toString();
        $argsVar = $frame->calledArgs[2]->resolveIndirect();
        $inner = self::resolveInnerIterator($object);
        SplIteratorSupport::ensurePinnedObjectAlive($inner);
        if (null === $frame->vmContext || null === $frame->vmContext->runtime) {
            throw new \LogicException('IteratorIterator::__call() requires VM runtime');
        }
        $vm = $frame->vmContext->runtime->vm;
        if (!$vm->hasInstanceMethod($inner->class, strtolower($method))) {
            throw new \Error('Call to undefined method '.$inner->class->name.'::'.$method.'()');
        }
        $extra = [];
        if (Variable::TYPE_ARRAY === $argsVar->type) {
            foreach ($argsVar->toArray()->iterateKeyed(true) as [, $valueVar]) {
                $extra[] = $valueVar;
            }
        }
        $result = [] === $extra
            ? $vm->invokeInstanceMethod($inner, $method)
            : $vm->invokeInstanceMethod($inner, $method, ...$extra);
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($result);
        }
    }

    private static function resolveInnerIterator(ObjectEntry $object): ObjectEntry
    {
        // CachingIterator / RecursiveCachingIterator keep inner in their own store (#24287).
        if (SplCachingIteratorStorage::hasState($object)) {
            return SplCachingIteratorStorage::inner($object);
        }

        return SplDualIteratorStorage::inner($object);
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
        // php-src zim_RecursiveIteratorIterator_getDepth — ZEND_PARSE_PARAMETERS_NONE (#30956).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::getDepth', 0);
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
        // php-src zim_RecursiveIteratorIterator_setMaxDepth — optional max_depth; at most 1 (#30956).
        $this->requireUserArgCountRange($frame, 'RecursiveIteratorIterator::setMaxDepth', 0, 1);
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
        // php-src zim_RecursiveIteratorIterator_getMaxDepth — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::getMaxDepth', 0);
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
        // php-src zim_RecursiveIteratorIterator_getSubIterator — optional level; at most 1 (#30956).
        $this->requireUserArgCountRange($frame, 'RecursiveIteratorIterator::getSubIterator', 0, 1);
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
        if (null === $inner) {
            $frame->returnVar->null();

            return;
        }
        SplIteratorSupport::ensurePinnedObjectAlive($inner);
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
        // php-src zim_RecursiveIteratorIterator_getInnerIterator — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::getInnerIterator', 0);
        if (null === $frame->returnVar) {
            return;
        }
        // php-src RecursiveIteratorIterator::getInnerIterator — current stack
        // sub-iterator (SPL_FETCH_SUB_ELEMENT), not the original root (#20091).
        $inner = SplDualIteratorStorage::getSubIterator($object, null);
        if (null === $inner) {
            $frame->returnVar->null();

            return;
        }
        SplIteratorSupport::ensurePinnedObjectAlive($inner);
        $frame->returnVar->object($inner);
    }
}

final class RecursiveIteratorIteratorGetSubPath extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSubPath');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::getSubPath()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = SplDualIteratorStorage::callCurrentSubMethod($frame, $object, 'getSubPath')->resolveIndirect();
        $frame->returnVar->string($result->toString());
    }
}

final class RecursiveIteratorIteratorGetSubPathname extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSubPathname');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::getSubPathname()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = SplDualIteratorStorage::callCurrentSubMethod($frame, $object, 'getSubPathname')->resolveIndirect();
        $frame->returnVar->string($result->toString());
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
        // php-src zim_RecursiveIteratorIterator_callHasChildren — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::callHasChildren', 0);
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
        // php-src zim_RecursiveIteratorIterator_callGetChildren — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::callGetChildren', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $child = SplDualIteratorStorage::callGetChildren($frame, $object);
        $frame->returnVar->object($child);
    }
}

/** php-src empty hook — overridden by user subclasses (#20146). */
final class RecursiveIteratorIteratorBeginIteration extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('beginIteration');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::beginIteration()'
        );
        // php-src zim_RecursiveIteratorIterator_beginIteration — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::beginIteration', 0);
    }
}

/** php-src empty hook — overridden by user subclasses (#20146). */
final class RecursiveIteratorIteratorEndIteration extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('endIteration');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::endIteration()'
        );
        // php-src zim_RecursiveIteratorIterator_endIteration — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::endIteration', 0);
    }
}

/** php-src empty hook — overridden by user subclasses (#20146). */
final class RecursiveIteratorIteratorBeginChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('beginChildren');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::beginChildren()'
        );
        // php-src zim_RecursiveIteratorIterator_beginChildren — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::beginChildren', 0);
    }
}

/** php-src empty hook — overridden by user subclasses (#20146). */
final class RecursiveIteratorIteratorEndChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('endChildren');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::endChildren()'
        );
        // php-src zim_RecursiveIteratorIterator_endChildren — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::endChildren', 0);
    }
}

/** php-src empty hook — overridden by user subclasses (#20146). */
final class RecursiveIteratorIteratorNextElement extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('nextElement');
    }

    public function execute(Frame $frame): void
    {
        SplIteratorSupport::receiverIsA(
            $frame,
            RecursiveIteratorIteratorBuiltin::CLASS_LC,
            'RecursiveIteratorIterator::nextElement()'
        );
        // php-src zim_RecursiveIteratorIterator_nextElement — ZEND_PARSE_PARAMETERS_NONE (#31010).
        $this->requireExactUserArgCount($frame, 'RecursiveIteratorIterator::nextElement', 0);
    }
}
