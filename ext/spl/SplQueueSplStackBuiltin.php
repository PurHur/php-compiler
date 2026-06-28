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

    /** php-src IT_MODE_FIFO | IT_MODE_KEEP */
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
        foreach (['iterator', 'traversable', 'countable', 'arrayaccess', 'serializable'] as $iface) {
            if (isset($ctx->classes[$iface])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SplQueueConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
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

    /** php-src IT_MODE_LIFO | IT_MODE_KEEP */
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
        foreach (['iterator', 'traversable', 'countable', 'arrayaccess', 'serializable'] as $iface) {
            if (isset($ctx->classes[$iface])
                && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->constructor = new SplStackConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['setiteratormode'] = new SplStackSetIteratorMode();
        $entry->methodVisibility['setiteratormode'] = $pub;
        $entry->methodNames['setiteratormode'] = 'setIteratorMode';
        $entry->methods['getiteratormode'] = new SplStackGetIteratorMode();
        $entry->methodVisibility['getiteratormode'] = $pub;
        $entry->methodNames['getiteratormode'] = 'getIteratorMode';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['__construct'], $entry->methods['getiteratormode']);
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
        $object = SplIteratorSupport::receiver(
            $frame,
            SplQueueBuiltin::CLASS_LC,
            'SplQueue::__construct()'
        );
        SplDoublyLinkedListBuiltin::init($object);
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
        $object = SplIteratorSupport::receiver(
            $frame,
            SplStackBuiltin::CLASS_LC,
            'SplStack::__construct()'
        );
        SplDoublyLinkedListBuiltin::init($object);
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
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'SplQueue::enqueue() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
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
        SplIteratorSupport::receiverIsA(
            $frame,
            SplQueueBuiltin::CLASS_LC,
            'SplQueue::setIteratorMode()'
        );
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
        SplIteratorSupport::receiverIsA(
            $frame,
            SplQueueBuiltin::CLASS_LC,
            'SplQueue::getIteratorMode()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplQueueBuiltin::IT_MODE_FIFO);
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
        SplIteratorSupport::receiverIsA(
            $frame,
            SplStackBuiltin::CLASS_LC,
            'SplStack::setIteratorMode()'
        );
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
        SplIteratorSupport::receiverIsA(
            $frame,
            SplStackBuiltin::CLASS_LC,
            'SplStack::getIteratorMode()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplStackBuiltin::IT_MODE_LIFO);
    }
}
