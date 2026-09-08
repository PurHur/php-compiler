<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * LLVM structFieldMap helpers for {@see Context} (#36387).
 *
 * Extracted from {@see ContextTypeAndStructMap} so structField* /
 * resolveStructMapName / stripLlvmUniquifySuffix stay a separate TU from
 * castToBool / getTypeFromType / getStringFromType (split-TU / size-budget
 * ratchet, #36199 / #36403).
 *
 * Used via {@code use ContextStructFieldMap;} on {@see Context}.
 * Type↔LLVM map: {@see ContextTypeAndStructMap}.
 *
 * No new C ABI. php-src analogy: zend_object_handlers / property offset maps
 * and struct layout live beside the executor (Zend/zend_object_handlers.c,
 * Zend/zend_types.h) rather than inside scalar type conversion.
 */
trait ContextStructFieldMap
{
    /**
     * Struct type name for structGep on an LLVM Value (pointer or by-value struct).
     */
    public function structNameForValue(PHPLLVM\Value $value): string
    {
        $ty = $value->typeOf();
        if (PHPLLVM\Type::KIND_POINTER === $ty->getKind()) {
            return $this->getStringFromType($ty->getElementType());
        }

        return $this->getStringFromType($ty);
    }

    /** structFieldMap index for a struct value or pointer (issue #1880). */
    public function structFieldIndex(PHPLLVM\Value $structOrPtr, string $field): int
    {
        $map = $this->structFieldsFor($structOrPtr);
        if (!isset($map[$field])) {
            $structName = $this->resolveStructMapName(
                PHPLLVM\Type::KIND_POINTER === $structOrPtr->typeOf()->getKind()
                    ? $structOrPtr->typeOf()->getElementType()
                    : $structOrPtr->typeOf()
            );
            throw new \LogicException(
                "structFieldIndex: struct {$structName} has no field {$field} (llvm {$structOrPtr->typeOf()->toString()})"
            );
        }

        return $map[$field];
    }

    /**
     * Field map for a struct value/pointer, resolving LLVM CreateNamed suffixes (#36387).
     *
     * @return array<string, int>
     */
    public function structFieldsFor(PHPLLVM\Value $structOrPtr): array
    {
        $ty = $structOrPtr->typeOf();
        $structTy = PHPLLVM\Type::KIND_POINTER === $ty->getKind()
            ? $ty->getElementType()
            : $ty;

        return $this->structFieldsForType($structTy);
    }

    /**
     * Field map for an LLVM struct type name, resolving `__string__.2` → `__string__` (#36387).
     *
     * @return array<string, int>
     */
    public function structFieldsForTypeName(string $llvmName): array
    {
        if (isset($this->structFieldMap[$llvmName]) && is_array($this->structFieldMap[$llvmName])) {
            return $this->structFieldMap[$llvmName];
        }
        $stripped = self::stripLlvmUniquifySuffix($llvmName);
        if (
            $stripped !== $llvmName
            && isset($this->structFieldMap[$stripped])
            && is_array($this->structFieldMap[$stripped])
        ) {
            // Lazy-alias so subsequent raw map lookups in older call sites succeed.
            $this->structFieldMap[$llvmName] = $this->structFieldMap[$stripped];

            return $this->structFieldMap[$llvmName];
        }

        throw new \LogicException("structFieldsForTypeName: no field map for {$llvmName}");
    }

    /**
     * @return array<string, int>
     */
    public function structFieldsForType(PHPLLVM\Type $structTy): array
    {
        $name = $this->resolveStructMapName($structTy);
        if (!isset($this->structFieldMap[$name]) || !is_array($this->structFieldMap[$name])) {
            throw new \LogicException(
                'structFieldsForType: no field map for '.$name.' (llvm '.$structTy->toString().')'
            );
        }
        // If LLVM name is uniquified, alias it for raw call sites (#36387).
        if (method_exists($structTy, 'getName')) {
            $llvmName = (string) $structTy->getName();
            if ($llvmName !== $name && $llvmName !== '') {
                $this->structFieldMap[$llvmName] = $this->structFieldMap[$name];
            }
        }

        return $this->structFieldMap[$name];
    }

    /** Map an LLVM struct type to a structFieldMap key (issue #1880). */
    private function resolveStructMapName(PHPLLVM\Type $structTy): string
    {
        $name = $this->getStringFromType($structTy);
        if ('unknown' !== $name) {
            $base = rtrim($name, '*');
            if (isset($this->structFieldMap[$base])) {
                return $base;
            }
            $stripped = self::stripLlvmUniquifySuffix($base);
            if ($stripped !== $base && isset($this->structFieldMap[$stripped])) {
                return $stripped;
            }
        }
        if (method_exists($structTy, 'getName')) {
            $llvmName = (string) $structTy->getName();
            if (isset($this->structFieldMap[$llvmName])) {
                return $llvmName;
            }
            // CreateNamed after bitcode uniqueifies (__string__.2); map to the seeded core (#36387).
            $stripped = self::stripLlvmUniquifySuffix($llvmName);
            if ($stripped !== $llvmName && isset($this->structFieldMap[$stripped])) {
                return $stripped;
            }
        }
        $repr = $structTy->toString();
        foreach (array_keys($this->structFieldMap) as $candidate) {
            if (str_contains($repr, $candidate)) {
                return $candidate;
            }
        }

        return $name;
    }

    /** LLVM CreateNamed uniquify suffix: `__string__.2` → `__string__` (#36387). */
    public static function stripLlvmUniquifySuffix(string $name): string
    {
        if (preg_match('/^(.+)\.(\d+)$/', $name, $m)) {
            return $m[1];
        }

        return $name;
    }
}
