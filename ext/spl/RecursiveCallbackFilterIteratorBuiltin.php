<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\VmCallable;
use PHPCompiler\ext\standard\VmClosureCall;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * RecursiveCallbackFilterIterator — SPL callback filter (php-src ext/spl/spl_iterators.c; #6338).
 */
final class RecursiveCallbackFilterIteratorBuiltin
{
    public const CLASS_LC = 'recursivecallbackfilteriterator';

    /** @var array<int, array{inner: Variable, callback: Variable, callbackClosure: ?ClosureState, ctx: Context}> */
    private static array $store = [];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $prot = CfgFunc::FLAG_PROTECTED;
        $entry = new ClassEntry('RecursiveCallbackFilterIterator');
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }
        if (isset($ctx->classes['recursiveiterator'])) {
            $entry->interfaces[] = 'recursiveiterator';
        }
        if (isset($ctx->classes['outeriterator'])) {
            $entry->interfaces[] = 'outeriterator';
        }

        $entry->constructor = new RecursiveCallbackFilterIteratorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['accept'] = new RecursiveCallbackFilterIteratorAccept();
        $entry->methodVisibility['accept'] = $prot;
        $entry->methods['rewind'] = new RecursiveCallbackFilterIteratorRewind();
        $entry->methodVisibility['rewind'] = $pub;
        $entry->methods['next'] = new RecursiveCallbackFilterIteratorNext();
        $entry->methodVisibility['next'] = $pub;
        $entry->methods['valid'] = new RecursiveCallbackFilterIteratorValid();
        $entry->methodVisibility['valid'] = $pub;
        $entry->methods['current'] = new RecursiveCallbackFilterIteratorCurrent();
        $entry->methodVisibility['current'] = $pub;
        $entry->methods['key'] = new RecursiveCallbackFilterIteratorKey();
        $entry->methodVisibility['key'] = $pub;
        $entry->methods['getinneriterator'] = new RecursiveCallbackFilterIteratorGetInnerIterator();
        $entry->methodVisibility['getinneriterator'] = $pub;
        $entry->methodNames['getinneriterator'] = 'getInnerIterator';
        $entry->methods['getchildren'] = new RecursiveCallbackFilterIteratorGetChildren();
        $entry->methodVisibility['getchildren'] = $pub;
        $entry->methodNames['getchildren'] = 'getChildren';
        $entry->methods['haschildren'] = new RecursiveCallbackFilterIteratorHasChildren();
        $entry->methodVisibility['haschildren'] = $pub;
        $entry->methodNames['haschildren'] = 'hasChildren';

        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function init(ObjectEntry $object, Variable $inner, Variable $callback, Context $ctx): void
    {
        [$callbackCopy, $callbackClosure] = SplIteratorSupport::pinCallback($callback);
        self::$store[$object->id] = [
            'inner' => self::copyVar($inner),
            'callback' => $callbackCopy,
            'callbackClosure' => $callbackClosure,
            'ctx' => $ctx,
        ];
    }

    /**
     * @return array{inner: Variable, callback: Variable, callbackClosure: ?ClosureState, ctx: Context}
     */
    public static function state(ObjectEntry $object): array
    {
        if (!isset(self::$store[$object->id])) {
            throw new \LogicException('RecursiveCallbackFilterIterator state missing');
        }

        return self::$store[$object->id];
    }

    public static function fetch(Frame $frame, ObjectEntry $object): void
    {
        $vm = self::vm($object);
        $inner = self::state($object)['inner'];
        while ($vm->invokeForeachInstanceMethod($frame, $inner, 'valid')->toBool()) {
            if (self::callAccept($frame, $object)) {
                return;
            }
            $vm->invokeForeachInstanceMethod($frame, $inner, 'next');
        }
    }

    public static function callAccept(Frame $frame, ObjectEntry $object): bool
    {
        $vm = self::vm($object);
        $ctx = self::state($object)['ctx'];
        $inner = self::state($object)['inner'];
        $current = $vm->invokeForeachInstanceMethod($frame, $inner, 'current');
        $key = $vm->invokeForeachInstanceMethod($frame, $inner, 'key');
        $filter = new Variable();
        $filter->object($object);
        $callback = self::state($object)['callback'];
        $closure = self::state($object)['callbackClosure'];
        if (null !== $closure) {
            return VmClosureCall::invoke($ctx, $closure, $current, $key, $filter)->resolveIndirect()->toBool();
        }

        return VmCallable::invoke($ctx, $callback, $current, $key, $filter)->resolveIndirect()->toBool();
    }

    public static function createFromInnerAndCallback(
        Context $ctx,
        Variable $inner,
        Variable $callback
    ): Variable {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('RecursiveCallbackFilterIterator is not registered in this compiler build');
        }
        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::init($object, $inner, $callback, $ctx);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function vm(ObjectEntry $object): \PHPCompiler\VM
    {
        return self::state($object)['ctx']->runtime->vm;
    }

    private static function copyVar(Variable $source): Variable
    {
        $copy = new Variable();
        $copy->copyFrom($source->resolveIndirect());

        return $copy;
    }

    public static function requireRecursiveIteratorArg(
        Variable $var,
        string $function,
        int $argIndex,
        Context $ctx
    ): Variable {
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($iterator) must be of type RecursiveIterator, '
                .self::typeLabel($resolved).' given'
            );
        }
        if (!InterfaceCheck::entryImplements($resolved->toObject()->class, 'recursiveiterator', $ctx)) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($iterator) must be of type RecursiveIterator, '
                .$resolved->toObject()->class->name.' given'
            );
        }

        return $resolved;
    }

    public static function requireCallableArg(
        Variable $var,
        string $function,
        int $argIndex,
        Context $ctx
    ): Variable {
        $resolved = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($callback) must be a valid callback, no array or string given'
            );
        }
        if (!VmCallable::isCallable($ctx, $resolved)) {
            throw new \TypeError(
                $function.'(): Argument #'.$argIndex.' ($callback) must be a valid callback, no array or string given'
            );
        }

        return $resolved;
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

final class RecursiveCallbackFilterIteratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('RecursiveCallbackFilterIterator::__construct() expects at least 2 arguments');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('RecursiveCallbackFilterIterator::__construct() requires VM context');
        }
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::__construct()'
        );
        $inner = RecursiveCallbackFilterIteratorBuiltin::requireRecursiveIteratorArg(
            $frame->calledArgs[1],
            'RecursiveCallbackFilterIterator::__construct',
            1,
            $frame->vmContext
        );
        $callback = RecursiveCallbackFilterIteratorBuiltin::requireCallableArg(
            $frame->calledArgs[2],
            'RecursiveCallbackFilterIterator::__construct',
            2,
            $frame->vmContext
        );
        RecursiveCallbackFilterIteratorBuiltin::init($object, $inner, $callback, $frame->vmContext);
    }
}

final class RecursiveCallbackFilterIteratorAccept extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('accept');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::accept()'
        );
        SplIteratorSupport::setReturnBool(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::callAccept($frame, $object)
        );
    }
}

final class RecursiveCallbackFilterIteratorRewind extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('rewind');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::rewind()'
        );
        $vm = RecursiveCallbackFilterIteratorBuiltin::vm($object);
        $inner = RecursiveCallbackFilterIteratorBuiltin::state($object)['inner'];
        $vm->invokeForeachInstanceMethod($frame, $inner, 'rewind');
        RecursiveCallbackFilterIteratorBuiltin::fetch($frame, $object);
    }
}

final class RecursiveCallbackFilterIteratorNext extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('next');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::next()'
        );
        $vm = RecursiveCallbackFilterIteratorBuiltin::vm($object);
        $inner = RecursiveCallbackFilterIteratorBuiltin::state($object)['inner'];
        $vm->invokeForeachInstanceMethod($frame, $inner, 'next');
        RecursiveCallbackFilterIteratorBuiltin::fetch($frame, $object);
    }
}

final class RecursiveCallbackFilterIteratorValid extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('valid');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::valid()'
        );
        $vm = RecursiveCallbackFilterIteratorBuiltin::vm($object);
        $inner = RecursiveCallbackFilterIteratorBuiltin::state($object)['inner'];
        SplIteratorSupport::setReturnBool(
            $frame,
            $vm->invokeForeachInstanceMethod($frame, $inner, 'valid')->toBool()
        );
    }
}

final class RecursiveCallbackFilterIteratorCurrent extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('current');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::current()'
        );
        $vm = RecursiveCallbackFilterIteratorBuiltin::vm($object);
        $inner = RecursiveCallbackFilterIteratorBuiltin::state($object)['inner'];
        SplIteratorSupport::copyReturnFrom(
            $frame,
            $vm->invokeForeachInstanceMethod($frame, $inner, 'current')
        );
    }
}

final class RecursiveCallbackFilterIteratorKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('key');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::key()'
        );
        $vm = RecursiveCallbackFilterIteratorBuiltin::vm($object);
        $inner = RecursiveCallbackFilterIteratorBuiltin::state($object)['inner'];
        SplIteratorSupport::copyReturnFrom(
            $frame,
            $vm->invokeForeachInstanceMethod($frame, $inner, 'key')
        );
    }
}

final class RecursiveCallbackFilterIteratorGetInnerIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getInnerIterator');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::getInnerIterator()'
        );
        $inner = RecursiveCallbackFilterIteratorBuiltin::state($object)['inner'];
        SplIteratorSupport::copyReturnFrom($frame, $inner);
    }
}

final class RecursiveCallbackFilterIteratorHasChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('hasChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::hasChildren()'
        );
        $vm = RecursiveCallbackFilterIteratorBuiltin::vm($object);
        $inner = RecursiveCallbackFilterIteratorBuiltin::state($object)['inner'];
        SplIteratorSupport::setReturnBool(
            $frame,
            $vm->invokeForeachInstanceMethod($frame, $inner, 'hasChildren')->toBool()
        );
    }
}

final class RecursiveCallbackFilterIteratorGetChildren extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getChildren');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::CLASS_LC,
            'RecursiveCallbackFilterIterator::getChildren()'
        );
        $vm = RecursiveCallbackFilterIteratorBuiltin::vm($object);
        $state = RecursiveCallbackFilterIteratorBuiltin::state($object);
        $inner = $state['inner'];
        $childInner = $vm->invokeForeachInstanceMethod($frame, $inner, 'getChildren');
        SplIteratorSupport::copyReturnFrom(
            $frame,
            RecursiveCallbackFilterIteratorBuiltin::createFromInnerAndCallback(
                $state['ctx'],
                $childInner,
                $state['callback']
            )
        );
    }
}
