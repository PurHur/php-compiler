<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPTypes\Type;
use PHPLLVM;

/**
 * PHPTypes Type → LLVM type / string helpers for {@see Context} (#36387).
 *
 * Extracted from {@see ContextTypeAndStructMap} so unwrapNullableUnionType /
 * getTypeFromType / getStringFromType stay a separate TU from getTypeFromString
 * (split-TU / size-budget ratchet, #36199 / #36403).
 *
 * Used via {@code use ContextTypeFromPhpType;} on {@see Context}.
 * String→LLVM map: {@see ContextTypeAndStructMap}.
 * Bool cast: {@see ContextCastToBool}.
 * Struct field maps: {@see ContextStructFieldMap}.
 *
 * No new C ABI. php-src analogy: type conversion helpers live beside the
 * executor (Zend/zend_types.h, Zend/zend_operators.c) rather than inside the
 * compiler front-end.
 */
trait ContextTypeFromPhpType
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
}
