<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCfg\Func as CfgFunc;

/**
 * ArrayObject — array-as-object SPL builtin (php-src ext/spl/spl_array.c; #10711).
 */
final class ArrayObjectBuiltin
{
    public const CLASS_LC = 'arrayobject';

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('ArrayObject');
        if (isset($ctx->classes['iteratoraggregate'])) {
            $entry->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        if (isset($ctx->classes['arrayaccess'])) {
            $entry->interfaces[] = 'arrayaccess';
        }

        $entry->constructor = new ArrayObjectConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['getarraycopy'] = new ArrayObjectGetArrayCopy();
        $entry->methodVisibility['getarraycopy'] = $pub;
        $entry->methods['count'] = new ArrayObjectCount();
        $entry->methodVisibility['count'] = $pub;

        $ctx->classes[self::CLASS_LC] = $entry;
    }
}

final class ArrayObjectConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::__construct()'
        );
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = SplIteratorSupport::requireArrayArg(
                $frame->calledArgs[1],
                'ArrayObject::__construct',
                1
            )->duplicate();
        }
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            $flagsVar = $frame->calledArgs[2]->resolveIndirect();
            $flags = $flagsVar->toInt();
        }
        $iteratorClass = null;
        if (isset($frame->calledArgs[3])) {
            $iterVar = $frame->calledArgs[3]->resolveIndirect();
            if (\PHPCompiler\VM\Variable::TYPE_STRING === $iterVar->type) {
                $iteratorClass = $iterVar->toString();
            }
        }
        SplArrayStorage::init($object, $table, $flags, $iteratorClass);
    }
}

final class ArrayObjectGetArrayCopy extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getArrayCopy');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::getArrayCopy()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(SplArrayStorage::getArrayCopy($object));
    }
}

final class ArrayObjectCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = SplIteratorSupport::receiver(
            $frame,
            ArrayObjectBuiltin::CLASS_LC,
            'ArrayObject::count()'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(SplArrayStorage::count($object));
    }
}
