<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCfg\Func as CfgFunc;

/**
 * SplQueue / SplStack — SplDoublyLinkedList deque wrappers (php-src ext/spl/spl_dllist.c; #13222).
 */
final class SplQueueSplStackBuiltin
{
    public static function registerClasses(Context $ctx): void
    {
        SplDoublyLinkedListBuiltin::registerClass($ctx);
        SplQueueBuiltin::registerClass($ctx);
        SplStackBuiltin::registerClass($ctx);
    }
}

final class SplQueueBuiltin
{
    public const CLASS_LC = 'splqueue';

    /** php-src SPL_DLLIST_IT_FIFO (ext/spl/spl_dllist.h). */
    public const IT_MODE_FIFO = 0;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplQueue');
        $entry->parentLc = SplDoublyLinkedListBuiltin::CLASS_LC;
        // Zend rematerializes subclass ce->interfaces (Serializable first), not DDL parent order (#25797).
        $entry->interfaces = [];
        foreach (['serializable', 'arrayaccess', 'countable', 'traversable', 'iterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        // php-src: SplQueue has no reflected __construct (#22789).
        $entry->constructor = new SplQueueConstruct();
        foreach ([
            'enqueue' => SplQueueEnqueue::class,
            'dequeue' => SplQueueDequeue::class,
            'setiteratormode' => SplQueueSetIteratorMode::class,
            'getiteratormode' => SplQueueGetIteratorMode::class,
        ] as $lc => $class) {
            $entry->methods[$lc] = new $class();
            $entry->methodVisibility[$lc] = $pub;
        }
        $entry->methodNames['setiteratormode'] = 'setIteratorMode';
        $entry->methodNames['getiteratormode'] = 'getIteratorMode';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['enqueue'], $entry->methods['dequeue']);
    }
}

final class SplStackBuiltin
{
    public const CLASS_LC = 'splstack';

    /** php-src SPL_DLLIST_IT_LIFO (ext/spl/spl_dllist.h). */
    public const IT_MODE_LIFO = 2;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC]) && self::classIsComplete($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = isset($ctx->classes[self::CLASS_LC])
            ? $ctx->classes[self::CLASS_LC]
            : new ClassEntry('SplStack');
        $entry->parentLc = SplDoublyLinkedListBuiltin::CLASS_LC;
        // Zend rematerializes subclass ce->interfaces (Serializable first), not DDL parent order (#25797).
        $entry->interfaces = [];
        foreach (['serializable', 'arrayaccess', 'countable', 'traversable', 'iterator'] as $iface) {
            if (isset($ctx->classes[$iface])) {
                $entry->interfaces[] = $iface;
            }
        }

        // php-src: SplStack has no reflected __construct (#22789).
        $entry->constructor = new SplStackConstruct();
        $entry->methods['setiteratormode'] = new SplStackSetIteratorMode();
        $entry->methodVisibility['setiteratormode'] = $pub;
        $entry->methodNames['setiteratormode'] = 'setIteratorMode';
        $entry->methods['getiteratormode'] = new SplStackGetIteratorMode();
        $entry->methodVisibility['getiteratormode'] = $pub;
        $entry->methodNames['getiteratormode'] = 'getIteratorMode';
        $entry->methods['top'] = new SplStackTop();
        $entry->methodVisibility['top'] = $pub;

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['getiteratormode'], $entry->methods['top'])
            && null !== $entry->constructor;
    }
}

final class SplQueueConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplQueueBuiltin::CLASS_LC,
            'SplQueue::__construct()'
        );
        SplDoublyLinkedListBuiltin::init($object, SplDoublyLinkedListBuiltin::IT_MODE_FIX);
    }
}

final class SplStackConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplStackBuiltin::CLASS_LC,
            'SplStack::__construct()'
        );
        SplDoublyLinkedListBuiltin::init(
            $object,
            SplDoublyLinkedListBuiltin::IT_MODE_LIFO | SplDoublyLinkedListBuiltin::IT_MODE_FIX
        );
    }
}

final class SplQueueEnqueue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('enqueue');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplQueueBuiltin::CLASS_LC,
            'SplQueue::enqueue()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_ARGS(1, 1) (#30964)
        $this->requireExactUserArgCount($frame, 'SplQueue::enqueue', 1);
        SplDoublyLinkedListBuiltin::push($object, $frame->calledArgs[1]);
    }
}

final class SplQueueDequeue extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('dequeue');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplQueueBuiltin::CLASS_LC,
            'SplQueue::dequeue()'
        );
        // php-src: ZEND_PARSE_PARAMETERS_NONE (#30964)
        $this->requireExactUserArgCount($frame, 'SplQueue::dequeue', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::shift($object));
    }
}

final class SplQueueSetIteratorMode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setIteratorMode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplQueueBuiltin::CLASS_LC,
            'SplQueue::setIteratorMode()'
        );
        // php-src ACE cites defining class SplDoublyLinkedList (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::setIteratorMode', 1);
        $mode = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $newMode = SplDoublyLinkedListBuiltin::setIteratorMode($object, $mode);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($newMode);
    }
}

final class SplQueueGetIteratorMode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIteratorMode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplQueueBuiltin::CLASS_LC,
            'SplQueue::getIteratorMode()'
        );
        // php-src: method on SplDoublyLinkedList; ACE cites defining class
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::getIteratorMode', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplDoublyLinkedListBuiltin::getIteratorMode($object));
    }
}

final class SplStackSetIteratorMode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setIteratorMode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplStackBuiltin::CLASS_LC,
            'SplStack::setIteratorMode()'
        );
        // php-src ACE cites defining class SplDoublyLinkedList (#30964)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::setIteratorMode', 1);
        $mode = $frame->calledArgs[1]->resolveIndirect()->toInt();
        $newMode = SplDoublyLinkedListBuiltin::setIteratorMode($object, $mode);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($newMode);
    }
}

final class SplStackGetIteratorMode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIteratorMode');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplStackBuiltin::CLASS_LC,
            'SplStack::getIteratorMode()'
        );
        // php-src: method on SplDoublyLinkedList; ACE cites defining class
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::getIteratorMode', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplDoublyLinkedListBuiltin::getIteratorMode($object));
    }
}

final class SplStackTop extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('top');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            SplStackBuiltin::CLASS_LC,
            'SplStack::top()'
        );
        // php-src: method lives on SplDoublyLinkedList; ACE cites defining class (#30911)
        $this->requireExactUserArgCount($frame, 'SplDoublyLinkedList::top', 0);
        SplIteratorSupport::copyReturnFrom($frame, SplDoublyLinkedListBuiltin::top($object));
    }
}
