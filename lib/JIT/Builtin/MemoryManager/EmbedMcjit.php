<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\MemoryManager;

use PHPCompiler\JIT\Builtin\MemoryManager;
use PHPLLVM;

/**
 * MCJIT embed allocator — module-local bump heap (no libc/Zend dlsym; #98, #2055).
 */
final class EmbedMcjit extends MemoryManager
{
    private const HEAP_BYTES = 8388608;

    public function register(): void
    {
        parent::register();
    }

    public function implement(): void
    {
        $i8p = $this->context->getTypeFromString('int8*');
        $sizeT = $this->context->getTypeFromString('size_t');
        $i8 = $this->context->getTypeFromString('int8');

        $heapArray = $i8->arrayType(self::HEAP_BYTES);
        $heapGlobal = $this->context->module->addGlobal($heapArray, 'phpc_mcjit_heap');
        $heapGlobal->setInitializer($heapArray->constNull());

        $offGlobal = $this->context->module->addGlobal($sizeT, 'phpc_mcjit_heap_off');
        $offGlobal->setInitializer($sizeT->constInt(0, false));

        $this->implementMalloc($i8p, $sizeT, $heapGlobal, $offGlobal);
        $this->implementRealloc($i8p, $sizeT);
        $this->implementFree();
    }

    private function implementMalloc(
        PHPLLVM\Type $i8p,
        PHPLLVM\Type $sizeT,
        PHPLLVM\Value $heapGlobal,
        PHPLLVM\Value $offGlobal
    ): void {
        $fn = $this->context->lookupFunction('__mm__malloc');
        $entry = $fn->appendBasicBlock('entry');
        $this->context->builder->positionAtEnd($entry);
        $size = $fn->getParam(0);
        $offPtr = $this->context->builder->pointerCast($offGlobal, $sizeT->pointerType(0));
        $off = $this->context->builder->load($offPtr);
        $newOff = $this->context->builder->addNoUnsignedWrap($off, $size);
        $this->context->builder->store($newOff, $offPtr);
        $heapPtr = $this->context->builder->pointerCast($heapGlobal, $i8p);
        $result = $this->context->builder->inBoundsGEP($heapPtr, $off);
        $this->context->builder->returnValue($result);
        $this->context->builder->clearInsertionPosition();
    }

    private function implementRealloc(PHPLLVM\Type $i8p, PHPLLVM\Type $sizeT): void
    {
        $fn = $this->context->lookupFunction('__mm__realloc');
        $entry = $fn->appendBasicBlock('entry');
        $this->context->builder->positionAtEnd($entry);
        $size = $fn->getParam(1);
        $alloc = $this->context->builder->call($this->context->lookupFunction('__mm__malloc'), $size);
        $this->context->builder->returnValue($alloc);
        $this->context->builder->clearInsertionPosition();
    }

    private function implementFree(): void
    {
        $fn = $this->context->lookupFunction('__mm__free');
        $entry = $fn->appendBasicBlock('entry');
        $this->context->builder->positionAtEnd($entry);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
    }
}
