<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\ext\spl\ArrayIteratorBuiltin;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * PHP 8.4 Range value object — inclusive int/string intervals (php-src ext/standard/range.c; #17427).
 */
final class RangeBuiltin
{
    public const CLASS_LC = 'range';

    /** @var array<int, array{start: Variable, end: Variable, step: ?Variable}> */
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
            : new ClassEntry('Range');
        foreach (['IteratorAggregate', 'Traversable'] as $iface) {
            $lc = strtolower($iface);
            if (isset($ctx->classes[$lc]) && !\in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }

        $entry->methods['from'] = new RangeFrom();
        $entry->methodVisibility['from'] = $pubStatic;
        $entry->methods['getiterator'] = new RangeGetIterator();
        $entry->methodVisibility['getiterator'] = $pub;
        $entry->methodNames['getiterator'] = 'getIterator';

        $entry->isInternal = true;
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /** @return array{start: Variable, end: Variable, step: ?Variable} */
    public static function state(ObjectEntry $object): array
    {
        $state = self::$store[$object->id] ?? null;
        if (null === $state) {
            throw new \LogicException('Range internal state missing in this compiler build');
        }

        return $state;
    }

    public static function createObject(
        Context $ctx,
        Variable $start,
        Variable $end,
        ?Variable $step
    ): Variable {
        $class = $ctx->classes[self::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('Range is not registered in this compiler build');
        }

        $object = new ObjectEntry($class);
        $object->constructed = true;
        self::$store[$object->id] = [
            'start' => self::cloneVar($start),
            'end' => self::cloneVar($end),
            'step' => null !== $step ? self::cloneVar($step) : null,
        ];

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($object);

        return $var;
    }

    public static function iteratorFor(Context $ctx, Frame $frame, ObjectEntry $object): Variable
    {
        $state = self::state($object);
        $table = VmRange::build($frame, $state['start'], $state['end'], $state['step']);

        $iteratorClass = $ctx->classes[ArrayIteratorBuiltin::CLASS_LC] ?? null;
        if (null === $iteratorClass) {
            throw new \LogicException('ArrayIterator is not registered in this compiler build');
        }

        $iterator = new ObjectEntry($iteratorClass);
        $iterator->constructed = true;
        ArrayIteratorBuiltin::init($iterator, $table);

        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($iterator);

        return $var;
    }

    private static function cloneVar(Variable $source): Variable
    {
        $copy = new Variable();
        $copy->copyFrom($source->resolveIndirect());

        return $copy;
    }

    private static function classIsComplete(ClassEntry $entry): bool
    {
        return isset($entry->methods['from'], $entry->methods['getiterator']);
    }
}

final class RangeFrom extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('from');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('Range::from() requires VM context in this compiler build');
        }
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'Range::from() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $step = $argc >= 3 ? $frame->calledArgs[2] : null;
        $result = RangeBuiltin::createObject(
            $frame->vmContext,
            $frame->calledArgs[0],
            $frame->calledArgs[1],
            $step
        );
        $frame->returnVar->copyFrom($result->resolveIndirect());
    }
}

final class RangeGetIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('Range::getIterator() called without $this');
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('Range::getIterator() requires VM context in this compiler build');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Range::getIterator() called on non-object');
        }
        $object = $receiver->toObject();
        if (RangeBuiltin::CLASS_LC !== strtolower($object->class->name)) {
            throw new \LogicException('Range::getIterator() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $iterator = RangeBuiltin::iteratorFor($frame->vmContext, $frame, $object);
        $frame->returnVar->copyFrom($iterator->resolveIndirect());
    }
}
