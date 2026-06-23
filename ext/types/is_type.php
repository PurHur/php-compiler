<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\types;

use PHPCompiler\Func\Internal;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\TypedPropertyCheck;
use PHPCompiler\VM\Variable;

use PHPLLVM\Builder;
use PHPLLVM\Value;

class is_type extends Internal {

    private int $type;

    public function __construct(string $name, int $type) {
        parent::__construct($name);
        $this->type = $type;
    }

    public function execute(Frame $frame): void {
        if (count($frame->calledArgs) !== 1) {
            throw new \LogicException("Expecting exactly a single argument to {$this->name}()");
        }
        $var = $frame->calledArgs[0]->resolveIndirect();
        TypedPropertyCheck::assertReadable($var);
        if (!is_null($frame->returnVar)) {
            if (Variable::TYPE_OBJECT === $this->type) {
                // Zend is_object(): enum case operands are objects (zend_enum.c, #5448).
                $frame->returnVar->bool(
                    Variable::TYPE_OBJECT === $var->type
                    || Variable::TYPE_ENUM_CASE === $var->type
                );

                return;
            }
            $frame->returnVar->bool($var->type === $this->type);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ... $args): Value {
        $this->context = $context;
        if (count($args) !== 1) {
            throw new \LogicException('Too few args passed to ' . $this->name . '()');
        }
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            TypedPropertyUninitGuard::emitBeforeRead($context, $args[0]);
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromBool($this->type === Variable::TYPE_ARRAY);
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $this->context->constantFromBool($this->type === Variable::TYPE_INTEGER);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $this->context->constantFromBool($this->type === Variable::TYPE_FLOAT);
            case JITVariable::TYPE_NATIVE_BOOL:
                return $this->context->constantFromBool($this->type === Variable::TYPE_BOOLEAN);
            case JITVariable::TYPE_STRING:
                return $this->context->constantFromBool($this->type === Variable::TYPE_STRING);
            case JITVariable::TYPE_NULL:
                return $this->context->constantFromBool($this->type === Variable::TYPE_NULL);
            case JITVariable::TYPE_HASHTABLE:
                return $this->context->constantFromBool($this->type === Variable::TYPE_ARRAY);
            case JITVariable::TYPE_OBJECT:
                if (Variable::TYPE_NULL === $this->type) {
                    $ptr = JITVariable::KIND_VALUE === $args[0]->kind
                        ? $args[0]->value
                        : $context->builder->load($args[0]->value);

                    return $context->builder->icmp(
                        Builder::INT_EQ,
                        $ptr,
                        $ptr->typeOf()->constNull()
                    );
                }
                if (Variable::TYPE_OBJECT === $this->type) {
                    return $context->constantFromBool(true);
                }

                return $context->constantFromBool(false);
            case JITVariable::TYPE_VALUE:
                if (Variable::TYPE_STRING === $this->type && JITVariable::KIND_VARIABLE === $args[0]->kind) {
                    $slotTy = $context->getStringFromType($args[0]->value->typeOf());
                    if ('__string__*' === $slotTy || '__string__**' === $slotTy) {
                        return $context->constantFromBool(true);
                    }
                }
                $loaded = JitValueBox::valuePtrFromVariable($context, $args[0]);
                $typeField = $context->structFieldMap['__value__']['type'];
                $typeByte = $context->builder->load(
                    $context->builder->structGep($loaded, $typeField)
                );
                $i8 = $context->getTypeFromString('int8');
                $expectedFull = $i8->constInt(
                    JITVariable::jitTypeByteFromVmType($this->type),
                    false
                );
                $matchFull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expectedFull);
                if (Variable::TYPE_OBJECT === $this->type) {
                    $enumCaseTy = $i8->constInt(Variable::TYPE_ENUM_CASE, false);
                    $matchEnum = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTy);

                    return $context->builder->or($matchFull, $matchEnum);
                }
                if (Variable::TYPE_STRING === $this->type) {
                    $tag = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
                    $matchTag = $context->builder->icmp(
                        Builder::INT_EQ,
                        $tag,
                        $i8->constInt(Variable::TYPE_STRING, false)
                    );

                    return $context->builder->or($matchFull, $matchTag);
                }

                return $matchFull;
            default:
                throw new \LogicException('Non-implemented type handled for ' . $this->name . '(): ' . JITVariable::getStringType($args[0]->type));
        }
    }

}