<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM;

/**
 * LLVM zend_is_true / castToBool helpers for {@see Context} (#36387).
 *
 * Extracted from {@see ContextTypeAndStructMap} so castToBool stays a
 * separate TU from getTypeFromType / getStringFromType / getTypeFromString
 * (split-TU / size-budget ratchet, #36199 / #36403).
 *
 * Used via {@code use ContextCastToBool;} on {@see Context}.
 * Type↔LLVM map: {@see ContextTypeAndStructMap}.
 * Struct field maps: {@see ContextStructFieldMap}.
 *
 * No new C ABI. php-src analogy: zend_is_true lives in Zend/zend_operators.c
 * beside the executor rather than inside scalar type conversion / layout maps.
 */
trait ContextCastToBool
{
    public function castToBool(PHPLLVM\Value $value): PHPLLVM\Value {
        $type = $value->typeOf();
        $typeName = $this->getStringFromType($type);
        // CreateNamed uniquify (`__object__.2*`) — same as Call\Native (#36382).
        if (str_starts_with($typeName, '__object__') && str_ends_with($typeName, '*')) {
            $typeName = '__object__*';
        } elseif (str_starts_with($typeName, '__hashtable__') && str_ends_with($typeName, '*')) {
            $typeName = '__hashtable__*';
        } elseif (str_starts_with($typeName, '__string__') && str_ends_with($typeName, '*')) {
            $typeName = '__string__*';
        } elseif (str_starts_with($typeName, '__value__') && str_ends_with($typeName, '*')) {
            $typeName = '__value__*';
        }
        switch ($typeName) {
            case 'bool':
            case 'int1':
                return $value;
            case 'int8':
            case 'unsigned int':
            case 'long long':
            case 'int32':
            case 'int64':
            case 'size_t':
                return $this->builder->icmp($this->builder::INT_NE, $value, $type->constInt(0, false));
            case 'double':
            case 'float':
                // zend_is_true(IS_DOUBLE): != 0.0 including NaN (#35220 JUMPIF on native float).
                return $this->builder->fcmp(
                    $this->builder::REAL_UNE,
                    $value,
                    $type->constReal(0.0)
                );
            case '__value__':
            case '__value__*':
                $ptr = $value;
                if ('__value__' === $this->getStringFromType($type)) {
                    $slot = BasicBlockHelper::entryAlloca($this, $type);
                    $this->builder->store($value, $slot);
                    $ptr = $slot;
                }

                return \PHPCompiler\ext\standard\boolval::boxedTruthyScalar($this, $ptr);
            case '__string__':
                $slot = BasicBlockHelper::entryAlloca($this, $type);
                $this->builder->store($value, $slot);

                return \PHPCompiler\ext\standard\boolval::stringTruthy($this, $slot);
            case '__string__*':
                return \PHPCompiler\ext\standard\boolval::stringTruthy($this, $value);
            case '__object__':
                // zend_is_true / zend_std_cast_object_to_type(_IS_BOOL) → true (#32471 leftover of #32463).
                return $this->constantFromBool(true);
            case '__object__*':
                // Typed `?T $p` / omitted null args are `__object__*` null pointers, not
                // objects — zend_is_true(IS_NULL) is false. Always-true here made `??`
                // never fall through (Slim RouteCollectorProxy / AppFactory::create, #36382).
                // php-src: Zend/zend_operators.c zend_is_true / IS_NULL vs IS_OBJECT.
                return $this->builder->icmp(
                    $this->builder::INT_NE,
                    $value,
                    $type->constNull()
                );
            case '__hashtable__':
            case '__hashtable__*':
                // zend_is_true(IS_ARRAY): zend_hash_num_elements ? true : false (#32455 / #32471).
                $ht = $value;
                if ('__hashtable__' === $this->getStringFromType($type)) {
                    $slot = BasicBlockHelper::entryAlloca($this, $type);
                    $this->builder->store($value, $slot);
                    $ht = $slot;
                }
                $n = ArrayBuiltinHelper::getNumElements($this, $ht);

                return $this->builder->icmp(
                    $this->builder::INT_NE,
                    $n,
                    $n->typeOf()->constInt(0, false)
                );
        }
        throw new \LogicException("Unknown bool cast from type: " . $this->getStringFromType($type));
    }
}
