<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * LLVM string→type materialization for {@see Context} (#36387).
 *
 * Extracted from {@see ContextTypeAndStructMap} so getTypeFromString /
 * _getTypeFromString stay a separate TU from castToBool / PHPTypes→LLVM ({@see ContextTypeFromPhpType}) /
 * getTypeFromType / getStringFromType (split-TU / size-budget ratchet).
 *
 * Used via {@code use ContextTypeFromString;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: zend_parse_arg type strings and zval type
 * tags live beside conversion helpers (Zend/zend_API.c, Zend/zend_types.h)
 * rather than inside every cast / is_true path.
 */
trait ContextTypeFromString
{
    public function getTypeFromString(string $type): PHPLLVM\Type {
        if (!isset($this->typeMap[$type])) {
            $this->typeMap[$type] = $this->_getTypeFromString($type);
        }
        return $this->typeMap[$type];
    }

    public function _getTypeFromString(string $type): PHPLLVM\Type {
        switch ($type) {
            case 'void':
                return $this->context->voidType();
            // LLVM forbids pointers-to-void in bitcode (Invalid type on LLVMParseBitcode).
            // Map void*/void** to i8*/i8** so full-module module.bc round-trips (#36387).
            case 'void*':
            case 'const void*':
                return $this->context->int8Type()->pointerType(0);
            case 'void**':
            case 'const void**':
                return $this->context->int8Type()->pointerType(0)->pointerType(0);
            case 'const char':
                return $this->context->int8Type();
            case 'char':
            case 'unsigned char':
            case 'int8':
            case 'uint8_t':
            case 'int8_t':
                return $this->context->int8Type();
            case 'int16':
            case 'short':
            case 'unsigned short':
            case 'uint16_t':
            case 'int16_t':
                return $this->context->int16Type();
            case 'int32':
            case 'int':
            case 'unsigned':
            case 'unsigned int':
            case 'uint32_t':
            case 'int32_t':
            case 'uint':
                return $this->context->int32Type();
            case 'int64':
            // NestedJIT / FFI may emit plain "long"; LP64 maps to i64 (#35424).
            case 'long':
            case 'unsigned long':
            case 'long long':
            case 'unsigned long long':
            case 'size_t':
                return $this->context->int64Type();
                //return $this->module->getModuleDataLayout()->intPointerType();
            case 'int1':
            case 'bool':
                return $this->context->int1Type();
            case 'float':
                return $this->context->floatType();
            case 'double':
                return $this->context->doubleType();

        }
        if (substr($type, -1) === '*') {
            $base = substr($type, 0, -1);
            // void***… and any peel that would form pointer-to-void (#36387).
            if ('void' === $base || 'const void' === $base) {
                return $this->context->int8Type()->pointerType(0);
            }

            return $this->getTypeFromString($base)->pointerType(0);
        }
        if (substr($type, -1) === ']') {
            // array type
            if (preg_match('(^(.*?)\\[(\d+)\\]$)', $type, $match)) {
                return $this->getTypeFromString($match[1])->arrayType((int) $match[2]);
            } else {
                throw new \LogicException("Could not parse type with array notation: $type");
            }
        }
        throw new \LogicException("Unsupported native type $type");
    }
}
