<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPTypes\Type;
use PHPLLVM;

/**
 * LLVM type↔string map helpers for {@see Context} (#36387).
 *
 * Extracted from {@see Context} so getTypeFromType / getStringFromType /
 * getTypeFromString stay a separate TU from the Context construction /
 * compile hub. Bool cast lives in {@see ContextCastToBool}; struct field
 * maps live in {@see ContextStructFieldMap} (split-TU / size-budget ratchet
 * toward Context ≤ 3.5k lines).
 *
 * Used via {@code use ContextTypeAndStructMap;} on {@see Context}.
 *
 * No new C ABI. php-src analogy: type conversion helpers live beside the
 * executor (Zend/zend_types.h, Zend/zend_operators.c) rather than inside the
 * compiler front-end.
 */
trait ContextTypeAndStructMap
{
    public function unwrapNullableUnionType(Type $type): Type
    {
        if (Type::TYPE_UNION === $type->type && [] !== ($type->subTypes ?? [])) {
            $nonNull = [];
            foreach ($type->subTypes as $sub) {
                if (Type::TYPE_NULL !== $sub->type) {
                    $nonNull[] = $sub;
                }
            }
            if (1 === count($nonNull)) {
                return $this->unwrapNullableUnionType($nonNull[0]);
            }
        }
        return $type;
    }

    public function getTypeFromType(Type $type): PHPLLVM\Type {
        $type = $this->unwrapNullableUnionType($type);
        switch ($type->type) {
            case Type::TYPE_LONG:
                return $this->getTypeFromString('long long');
            case Type::TYPE_BOOLEAN:
                return $this->getTypeFromString('bool');
            case Type::TYPE_STRING:
                return $this->getTypeFromString('__string__*');
            case Type::TYPE_OBJECT:
                // PHPTypes Type::fromDecl('mixed') mis-parses as object userType mixed (#12348 / #32728).
                if ('mixed' === strtolower((string) ($type->userType ?? ''))) {
                    return $this->getTypeFromString('__value__');
                }

                return $this->getTypeFromString('__object__*');
            case Type::TYPE_ARRAY:
                return $this->getTypeFromString('__hashtable__*');
            default:
                return $this->getTypeFromString('__value__');
        }
    }

    public function getStringFromType(PHPLLVM\Type $type): string {
        // else, try to figure it out:
        switch ($type->getKind()) {
            case PHPLLVM\Type::KIND_DOUBLE:
                return 'double';
            case PHPLLVM\Type::KIND_INTEGER:
                return 'int' . $this->llvm->lib->LLVMGetIntTypeWidth($type->type);
            case PHPLLVM\Type::KIND_POINTER:
                return $this->getStringFromType($type->getElementType()) . '*';
        }
        foreach ($this->typeMap as $name => $ptr) {
            if ($type->toString() === $ptr->toString()) {
                return $name;
            }
        }
        // CreateNamed after helper/bitcode merge uniqueifies (`__value__.2`); map back to
        // the seeded core name so Slim AOT guards see `__value__*` not `unknown*` (#36382).
        if (method_exists($type, 'getName')) {
            $llvmName = (string) $type->getName();
            if ('' !== $llvmName) {
                $stripped = self::stripLlvmUniquifySuffix($llvmName);
                if (isset($this->typeMap[$stripped])) {
                    return $stripped;
                }
                if (isset($this->typeMap[$llvmName])) {
                    return $llvmName;
                }
                if (isset($this->structFieldMap[$stripped])) {
                    return $stripped;
                }
            }
        }
        $repr = $type->toString();
        foreach (array_keys($this->typeMap) as $name) {
            if (str_contains($name, '*')) {
                continue;
            }
            if ('' !== $name && str_contains($repr, $name)) {
                return $name;
            }
        }

        return 'unknown';
    }

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
