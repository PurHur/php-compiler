<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Register Ds\Vector / Ds\Map / Ds\Set (#22549) + depth types (#28062, php-ds/ext-ds).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = \array_keys($ctx->classes);
        self::registerVector($ctx);
        self::registerMap($ctx);
        self::registerSet($ctx);
        require_once __DIR__.'/VmDsDepth.php';
        require_once __DIR__.'/DsDepthClasses.php';
        DsDepthClasses::register($ctx);
        foreach (\array_diff(\array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerVector(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsStorage::VECTOR_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\Vector');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $entry->constructor = new DsVectorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['count'] = new DsVectorCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methodNames['count'] = 'count';
        $ctx->classes[VmDsStorage::VECTOR_LC] = $entry;
    }

    private static function registerMap(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsStorage::MAP_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\Map');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $entry->constructor = new DsMapConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['count'] = new DsMapCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['get'] = new DsMapGet();
        $entry->methodVisibility['get'] = $pub;
        $entry->methodNames['get'] = 'get';
        $ctx->classes[VmDsStorage::MAP_LC] = $entry;
    }

    private static function registerSet(Context $ctx): void
    {
        if (isset($ctx->classes[VmDsStorage::SET_LC])) {
            return;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry = new ClassEntry('Ds\\Set');
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $entry->constructor = new DsSetConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methods['count'] = new DsSetCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methods['add'] = new DsSetAdd();
        $entry->methodVisibility['add'] = $pub;
        $entry->methods['contains'] = new DsSetContains();
        $entry->methodVisibility['contains'] = $pub;
        $ctx->classes[VmDsStorage::SET_LC] = $entry;
    }
}

final class DsVectorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::VECTOR_LC, 'Ds\\Vector::__construct');
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = VmDsStorage::requireArrayArg(
                $frame->calledArgs[1],
                'Ds\\Vector::__construct',
                1
            );
        }
        VmDsStorage::initVector($object, $table);
        $object->constructed = true;
    }
}

final class DsVectorCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::VECTOR_LC, 'Ds\\Vector::count');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDsStorage::vectorTable($object)->getNumElements());
    }
}

final class DsMapConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::MAP_LC, 'Ds\\Map::__construct');
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = VmDsStorage::requireArrayArg(
                $frame->calledArgs[1],
                'Ds\\Map::__construct',
                1
            );
        }
        VmDsStorage::initMap($object, $table);
        $object->constructed = true;
    }
}

final class DsMapCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::MAP_LC, 'Ds\\Map::count');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDsStorage::mapTable($object)->getNumElements());
    }
}

final class DsMapGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::MAP_LC, 'Ds\\Map::get');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'Ds\\Map::get() expects at least 1 argument, '.$argc.' given'
            );
        }
        $default = $frame->calledArgs[2] ?? null;
        $result = VmDsStorage::mapGet($object, $frame->calledArgs[1], $default);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom($result);
    }
}

final class DsSetConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::SET_LC, 'Ds\\Set::__construct');
        $table = new HashTable();
        if (isset($frame->calledArgs[1])) {
            $table = VmDsStorage::requireArrayArg(
                $frame->calledArgs[1],
                'Ds\\Set::__construct',
                1
            );
        }
        VmDsStorage::initSet($object, $table);
        $object->constructed = true;
    }
}

final class DsSetCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::SET_LC, 'Ds\\Set::count');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmDsStorage::setTable($object)->getNumElements());
    }
}

final class DsSetAdd extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('add');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::SET_LC, 'Ds\\Set::add');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'Ds\\Set::add() expects at least 1 argument, '.$argc.' given'
            );
        }
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            VmDsStorage::setAdd($object, $frame->calledArgs[$i]);
        }
    }
}

final class DsSetContains extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('contains');
    }

    public function execute(Frame $frame): void
    {
        $object = VmDsStorage::receiver($frame, VmDsStorage::SET_LC, 'Ds\\Set::contains');
        $argc = \count($frame->calledArgs) - 1;
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'Ds\\Set::contains() expects exactly 1 argument, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmDsStorage::setContains($object, $frame->calledArgs[1]));
    }
}
