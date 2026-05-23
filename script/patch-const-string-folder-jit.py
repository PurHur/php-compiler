#!/usr/bin/env python3
"""Apply ConstStringFolder / nullable JIT lowering fixes."""
from pathlib import Path

jit = Path('lib/JIT.php')
s = jit.read_text()

old = """        if (!is_null($block->func)) {
            $callbackType = '';
            if (null !== $block->func && '__construct' === strtolower($block->func->name)) {
                $callbackType = 'void';
            } elseif ($block->func->returnType instanceof Op\\Type\\Void_) {
                $callbackType = 'void';
            } elseif ($block->func->returnType instanceof Op\\Type\\Literal) {
                switch ($block->func->returnType->name) {
                    case 'void':
                        $callbackType = 'void';
                        break;
                    case 'int':
                        $callbackType = 'long long';
                        break;
                    case 'string':
                        $callbackType = '__string__*';
                        break;
                    case 'bool':
                        $callbackType = 'bool';
                        break;
                    case 'object':
                        $callbackType = '__object__*';
                        break;
                    case 'array':
                        $callbackType = '__hashtable__*';
                        break;
                    default:
                        $callbackType = '__value__';
                        break;
                }
            } else {
                $callbackType = '__value__';
            }"""
new = """        if (!is_null($block->func)) {
            $callbackType = $this->cfgFunctionReturnCallbackType($block->func) ?? '__value__';
            if ('__construct' === strtolower($block->func->name)) {
                $callbackType = 'void';
            }"""
if old not in s:
    raise SystemExit('compileBlock return block missing')
s = s.replace(old, new, 1)

old = """            foreach ($block->func->params as $idx => $param) {
                if (empty($param->result->usages)) {
                    // only compile for param
                    assert($param->declaredType instanceof Op\\Type\\Literal);
                    $rawType = Type::fromDecl($param->declaredType->name);
                } else {
                    $rawType = $param->result->type;
                }
                $type = $this->context->getTypeFromType($rawType);"""
new = """            foreach ($block->func->params as $idx => $param) {
                $rawType = $this->rawTypeFromCfgParam($param);
                $type = $this->context->getTypeFromType($rawType);"""
if old not in s:
    raise SystemExit('param loop missing')
s = s.replace(old, new, 1)

old = """        foreach ($block->func->params as $param) {
            if (empty($param->result->usages)) {
                assert($param->declaredType instanceof Op\\Type\\Literal);
                $rawType = Type::fromDecl($param->declaredType->name);
            } else {
                $rawType = $param->result->type;
            }
            $args[] = $this->context->getTypeFromType($rawType);
        }"""
new = """        foreach ($block->func->params as $param) {
            $args[] = $this->context->getTypeFromType($this->rawTypeFromCfgParam($param));
        }"""
if old not in s:
    raise SystemExit('stub param loop missing')
s = s.replace(old, new, 1)

helpers = '''
    private function rawTypeFromCfgParam(\\PHPCfg\\Op\\Expr\\Param $param): Type
    {
        $declared = null;
        if ($param->declaredType instanceof Op\\Type\\Literal) {
            $declared = Type::fromDecl($param->declaredType->name);
        } elseif ($param->declaredType instanceof Op\\Type\\Reference && null !== $param->declaredType->type) {
            $declared = Type::fromTypeDecl($param->declaredType->type);
        } elseif (null !== $param->declaredType) {
            try {
                $declared = Type::fromTypeDecl($param->declaredType);
            } catch (\\LogicException) {
                $declared = null;
            }
        }
        if (null !== $param->result->type && Type::TYPE_NULL !== $param->result->type->type) {
            return $param->result->type;
        }
        if (null !== $declared) {
            return $declared;
        }
        if (null !== $param->result->type) {
            return $param->result->type;
        }

        return Type::mixed();
    }

    private function rawTypeFromCfgReturn(?\\PHPCfg\\Op\\Type $returnType): ?Type
    {
        if (null === $returnType) {
            return null;
        }
        if ($returnType instanceof Op\\Type\\Literal) {
            return Type::fromDecl($returnType->name);
        }
        if ($returnType instanceof Op\\Type\\Reference && null !== $returnType->type) {
            return Type::fromTypeDecl($returnType->type);
        }
        try {
            return Type::fromTypeDecl($returnType);
        } catch (\\LogicException) {
            return null;
        }
    }

    private function callbackTypeFromPhptype(Type $type): ?string
    {
        $type = $this->context->unwrapNullableUnionType($type);
        switch ($type->type) {
            case Type::TYPE_LONG:
                return 'long long';
            case Type::TYPE_BOOLEAN:
                return 'bool';
            case Type::TYPE_STRING:
                return '__string__*';
            case Type::TYPE_OBJECT:
                return '__object__*';
            case Type::TYPE_ARRAY:
                return '__hashtable__*';
            case Type::TYPE_NULL:
                return '__value__';
            default:
                return null;
        }
    }

'''
marker = '    /**\n     * LLVM return type tag for a CFG function (must match compileBlock() signature lowering).\n     */\n    private function cfgFunctionReturnCallbackType'
if 'rawTypeFromCfgParam' not in s:
    s = s.replace(marker, helpers + marker, 1)

old_cfg = '''    private function cfgFunctionReturnCallbackType(?\\PHPCfg\\Func $cfgFunc): ?string
    {
        if (null === $cfgFunc) {
            return null;
        }
        if ('__construct' === strtolower($cfgFunc->name)) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\\Type\\Void_) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\\Type\\Literal) {
            switch ($cfgFunc->returnType->name) {
                case 'void':
                    return 'void';
                case 'int':
                    return 'long long';
                case 'string':
                    return '__string__*';
                case 'bool':
                    return 'bool';
                case 'object':
                    return '__object__*';
                case 'array':
                    return '__hashtable__*';
                default:
                    return '__value__';
            }
        }

        return '__value__';
    }'''
new_cfg = '''    private function cfgFunctionReturnCallbackType(?\\PHPCfg\\Func $cfgFunc): ?string
    {
        if (null === $cfgFunc) {
            return null;
        }
        if ('__construct' === strtolower($cfgFunc->name)) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\\Type\\Void_) {
            return 'void';
        }
        $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType);
        if (null !== $rawReturn) {
            $callback = $this->callbackTypeFromPhptype($rawReturn);
            if (null !== $callback) {
                return $callback;
            }
        }
        if ($cfgFunc->returnType instanceof Op\\Type\\Literal) {
            switch ($cfgFunc->returnType->name) {
                case 'void':
                    return 'void';
                case 'int':
                    return 'long long';
                case 'string':
                    return '__string__*';
                case 'bool':
                    return 'bool';
                case 'object':
                    return '__object__*';
                case 'array':
                    return '__hashtable__*';
                default:
                    return '__value__';
            }
        }

        return '__value__';
    }'''
if old_cfg not in s:
    raise SystemExit('cfgFunctionReturnCallbackType missing')
s = s.replace(old_cfg, new_cfg, 1)

old_store = '''            $this->context->builder->store(
                $this->context->helper->loadValue($value),
                $result->value
            );'''
new_store = '''            $toStore = $this->context->helper->loadValue($value);
            if (Variable::TYPE_VALUE === $value->type) {
                $destTy = $this->context->getStringFromType($result->value->typeOf());
                $srcTy = $this->context->getStringFromType($toStore->typeOf());
                if ('__value__*' === $destTy && '__value__' === $srcTy) {
                    $slot = JIT\\BasicBlockHelper::entryAlloca(
                        $this->context,
                        $this->context->getTypeFromString('__value__')
                    );
                    $this->context->builder->store($toStore, $slot);
                    $toStore = JIT\\JitValueBox::pointer($this->context, $slot);
                } elseif ('__value__*' === $srcTy && '__value__' === $destTy) {
                    $toStore = $this->context->builder->load($toStore);
                }
            }
            $this->context->builder->store(
                $toStore,
                $result->value
            );'''
if old_store not in s:
    raise SystemExit('assignOperand store missing')
s = s.replace(old_store, new_store, 1)

old_aov = '''        $dest = $this->context->getVariableFromOp($result);
        if ($dest->kind !== Variable::KIND_VARIABLE) {
            throw new \\LogicException('Cannot assign to a value');
        }
        $source = new Variable(
            $this->context,
            $this->jitTypeFromLlvmValue($value),
            Variable::KIND_VALUE,
            $value
        );
        if ($source->type === $dest->type) {
            $dest->free();
            $toStore = $value;
            if ('__value__*' === $this->context->getStringFromType($value->typeOf())) {
                $toStore = $this->context->builder->load($value);
            }
            $this->context->builder->store($toStore, $dest->value);
            $dest->addref();
            $this->copyValueBoxJitFlags($dest, $source);

            return;
        }
        $this->assignOperand($result, $source);
    }'''
new_aov = '''        $dest = $this->context->getVariableFromOp($result);
        if ($dest->kind !== Variable::KIND_VARIABLE) {
            throw new \\LogicException('Cannot assign to a value');
        }
        $valueTy = $this->context->getStringFromType($value->typeOf());
        $destTy = $this->context->getStringFromType($dest->value->typeOf());
        if (Variable::TYPE_NATIVE_BOOL === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
            if ('int1' === $valueTy || 'bool' === $valueTy) {
                $dest->free();
                $this->context->builder->store($value, $dest->value);
                $dest->addref();

                return;
            }
        }
        $source = new Variable(
            $this->context,
            $this->jitTypeFromLlvmValue($value),
            Variable::KIND_VALUE,
            $value
        );
        if ($source->type === $dest->type) {
            $dest->free();
            $toStore = $value;
            if ('__value__*' === $valueTy && '__value__' === $destTy) {
                $toStore = $this->context->builder->load($value);
            }
            $this->context->builder->store($toStore, $dest->value);
            $dest->addref();
            $this->copyValueBoxJitFlags($dest, $source);

            return;
        }
        $this->assignOperand($result, $source);
    }'''
if old_aov not in s:
    raise SystemExit('assignOperandValue missing')
s = s.replace(old_aov, new_aov, 1)

jit.write_text(s)

native = Path('lib/JIT/Call/Native.php')
ns = native.read_text()
if "case 'int1':" not in ns:
    ns = ns.replace("            case 'double':", """            case 'int1':
            case 'bool':
                switch ($arg->type) {
                    case Variable::TYPE_NATIVE_BOOL:
                        return $value;
                    case Variable::TYPE_NATIVE_LONG:
                        return $context->builder->truncOrBitCast(
                            $value,
                            $context->getTypeFromString('int1')
                        );
                    case Variable::TYPE_VALUE:
                        return $context->builder->truncOrBitCast(
                            $context->builder->call(
                                $context->lookupFunction('__value__readLong'),
                                \\PHPCompiler\\JIT\\JitValueBox::valuePtrFromVariable($context, $arg)
                            ),
                            $context->getTypeFromString('int1')
                        );
                }
                break;
            case 'double':""", 1)
    native.write_text(ns)

print('patch complete')
