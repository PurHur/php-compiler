<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ds;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * Ds\seq / Ds\map / Ds\set / Ds\heap namespace factories (#28062, php-ds/ext-ds php_ds.c).
 *
 * {@see Ds\seq()} returns {@see Ds\Vector} (Sequence) — classic Vector is the in-tree Sequence impl.
 */
abstract class DsFactoryFunction extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            $this->getName().'() is not implemented for JIT in this compiler build (issue #28062)'
        );
    }
}

final class ds_seq extends DsFactoryFunction
{
    public function __construct()
    {
        parent::__construct('Ds\\seq');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext;
        if (null === $ctx || !isset($ctx->classes[VmDsStorage::VECTOR_LC])) {
            throw new \Error('Ds\\seq(): Ds\\Vector is not available');
        }
        $table = new HashTable();
        if (isset($frame->calledArgs[0])) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $table = VmDsStorage::requireArrayArg($frame->calledArgs[0], 'Ds\\seq', 1);
            }
        }
        $object = new ObjectEntry($ctx->classes[VmDsStorage::VECTOR_LC]);
        VmDsStorage::initVector($object, $table);
        $object->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($object);
        }
    }
}

final class ds_map extends DsFactoryFunction
{
    public function __construct()
    {
        parent::__construct('Ds\\map');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext;
        if (null === $ctx || !isset($ctx->classes[VmDsStorage::MAP_LC])) {
            throw new \Error('Ds\\map(): Ds\\Map is not available');
        }
        $table = new HashTable();
        if (isset($frame->calledArgs[0])) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $table = VmDsStorage::requireArrayArg($frame->calledArgs[0], 'Ds\\map', 1);
            }
        }
        $object = new ObjectEntry($ctx->classes[VmDsStorage::MAP_LC]);
        VmDsStorage::initMap($object, $table);
        $object->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($object);
        }
    }
}

final class ds_set extends DsFactoryFunction
{
    public function __construct()
    {
        parent::__construct('Ds\\set');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext;
        if (null === $ctx || !isset($ctx->classes[VmDsStorage::SET_LC])) {
            throw new \Error('Ds\\set(): Ds\\Set is not available');
        }
        $table = new HashTable();
        if (isset($frame->calledArgs[0])) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $table = VmDsStorage::requireArrayArg($frame->calledArgs[0], 'Ds\\set', 1);
            }
        }
        $object = new ObjectEntry($ctx->classes[VmDsStorage::SET_LC]);
        VmDsStorage::initSet($object, $table);
        $object->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($object);
        }
    }
}

final class ds_heap extends DsFactoryFunction
{
    public function __construct()
    {
        parent::__construct('Ds\\heap');
    }

    public function execute(Frame $frame): void
    {
        $ctx = $frame->vmContext;
        if (null === $ctx || !isset($ctx->classes[VmDsDepth::HEAP_LC])) {
            throw new \Error('Ds\\heap(): Ds\\Heap is not available');
        }
        $table = new HashTable();
        if (isset($frame->calledArgs[0])) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $arg->type) {
                $table = VmDsStorage::requireArrayArg($frame->calledArgs[0], 'Ds\\heap', 1);
            }
        }
        $object = new ObjectEntry($ctx->classes[VmDsDepth::HEAP_LC]);
        VmDsDepth::initHeap($object, $table);
        $object->constructed = true;
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($object);
        }
    }
}
