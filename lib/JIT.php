<?php

# This file is generated, changes you make will be lost.
# Make your changes in /compiler/lib/JIT.pre instead.

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\IssetHelper;
use PHPCompiler\JIT\Variable;

use PHPCompiler\Func as CoreFunc;

use PHPLLVM;

class JIT {

    private static int $functionNumber = 0;
    private static int $blockNumber = 0;

    public int $optimizationLevel = 3;


    private array $stringConstant = [];
    private array $intConstant = [];
    private array $builtIns = [];

    private array $queue = [];

    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
    }

    public function compile(Block $block): PHPLLVM\Value {
        $return = $this->compileBlock($block);
        $this->runQueue();
        return $return;
    }

    public function compileFunc(CoreFunc $func): void {
        if ($func instanceof CoreFunc\PHP) {
            $this->compileBlock($func->block, $func->getName());
            $this->runQueue();
            return;
        } elseif ($func instanceof CoreFunc\JIT) {
            // No need to do anything, already compiled
            return;
        } elseif ($func instanceof CoreFunc\Internal) {
            $this->context->functionProxies[strtolower($func->getName())] = $func;
            return;
        }
        throw new \LogicException("Unknown func type encountered: " . get_class($func));
    }

    private function runQueue(): void {
        while (!empty($this->queue)) {
            $run = array_shift($this->queue);
            $this->compileBlockInternal($run[0], $run[1], ...$run[2]);
        }
    }

    private function compileBlock(Block $block, ?string $funcName = null): PHPLLVM\Value {
        if (!is_null($funcName)) {
            $internalName = $funcName;
        } else {
            $internalName = "internal_" . (++self::$functionNumber);
        }
        $args = [];
        $rawTypes = [];
        $argVars = [];
        if (!is_null($block->func)) {
            $callbackType = '';
            if ($block->func->returnType instanceof Op\Type\Void_) {
                $callbackType = 'void';
            } elseif ($block->func->returnType instanceof Op\Type\Literal) {
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
                    default:
                        throw new \LogicException("Non-void return types not supported yet");
                }
            } else {
                $callbackType = '__value__';
            }
            $returnType = $this->context->getTypeFromString($callbackType);
            $this->context->functionReturnType[strtolower($internalName)] = $callbackType;

            $callbackType .= '(*)(';
            $callbackSep = '';
            foreach ($block->func->params as $idx => $param) {
                if (empty($param->result->usages)) {
                    // only compile for param
                    assert($param->declaredType instanceof Op\Type\Literal);
                    $rawType = Type::fromDecl($param->declaredType->name);
                } else {
                    $rawType = $param->result->type;
                }
                $type = $this->context->getTypeFromType($rawType);
                $callbackType .= $callbackSep . $this->context->getStringFromType($type);
                $callbackSep = ', ';
                $rawTypes[] = $rawType;
                $args[] = $type;
            }
            $callbackType .= ')';
        } else {
            $callbackType = 'void(*)()';
            $returnType = $this->context->getTypeFromString('void');
        }

        $isVarArgs = false;

        $func = $this->context->module->addFunction(
            $internalName,
            $this->context->context->functionType(
                $returnType,
                $isVarArgs,
                ...$args
            )
        );

        foreach ($args as $idx => $arg) {
            $argVars[] = new Variable($this->context, Variable::getTypeFromType($rawTypes[$idx]), Variable::KIND_VALUE, $func->getParam($idx));
        }

        if (!is_null($funcName)) {
            $lcname = strtolower($funcName);
            $this->context->functions[$lcname] = $func;
            if ($isVarArgs) {
                $this->context->functionProxies[$lcname] = new JIT\Call\Vararg($func, $funcName, count($args));
            } else {
                $defaultArgs = $this->collectParamDefaults($block);
                $this->context->functionProxies[$lcname] = new JIT\Call\Native($func, $funcName, $args, $defaultArgs);
            }
        }

        $this->queue[] = [$func, $block, $argVars];
        if ($callbackType === 'void(*)()') {
            $this->context->addExport($internalName, $callbackType, $block);
        }
        return $func;
    }

    public function compileSubBlock(
        PHPLLVM\Value $func,
        Block $block,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        $limit = $block->nOpCodes;
        if ($limit > 0 && OpCode::TYPE_JUMP === $block->opCodes[$limit - 1]->type) {
            --$limit;
        }

        return $this->compileBlockInternal($func, $block, $limit, null, ...$args);
    }

    private function compileBlockInternal(
        PHPLLVM\Value $func,
        Block $block,
        ?int $limit = null,
        ?PHPLLVM\BasicBlock $entryBlock = null,
        Variable ...$args
    ): PHPLLVM\BasicBlock {
        if ($this->context->scope->blockStorage->contains($block)) {
            return $this->context->scope->blockStorage[$block];
        }
        if (null !== $entryBlock) {
            $origBasicBlock = $basicBlock = $entryBlock;
        } else {
            self::$blockNumber++;
            $origBasicBlock = $basicBlock = $func->appendBasicBlock('block_' . self::$blockNumber);
        }
        $this->context->scope->blockStorage[$block] = $basicBlock;
        $builder = $this->context->builder;
        $builder->positionAtEnd($basicBlock);
        // Handle hoisted variables
        foreach ($block->orig->hoistedOperands as $operand) {
            $this->context->makeVariableFromOp($func, $basicBlock, $block, $operand);
        }

        for ($i = 0, $length = null !== $limit ? $limit : count($block->opCodes); $i < $length; $i++) {
            $op = $block->opCodes[$i];
            switch ($op->type) {
                case OpCode::TYPE_ARG_RECV:
                    $this->assignOperand($block->getOperand($op->arg1), $args[$op->arg2]);
                    break;
                case OpCode::TYPE_ASSIGN:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $this->assignOperand($block->getOperand($op->arg2), $value);
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;  
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_ARRAY_DIM_FETCH_WRITE:
                    $forWrite = OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type;
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $resultOp = $block->getOperand($op->arg1);
                    if (null === $op->arg3) {
                        if (Variable::TYPE_STRING === $value->type) {
                            throw new \LogicException('[] is only supported for arrays');
                        }
                        $this->context->setVariableOp(
                            $resultOp,
                            JIT\HashTableHelper::reserveAppendSlot($this->context, $value)
                        );
                        break;
                    }
                    $dimOp = $block->getOperand($op->arg3);
                    $dim = $this->context->getVariableFromOp($dimOp);
                    if ($value->type === Variable::TYPE_STRING) {
                        $charPtr = StringOffsetHelper::dimFetch(
                            $this->context,
                            $value->value,
                            $dim
                        );
                        $this->context->makeVariableFromValueOp($charPtr, $resultOp);
                        break;
                    }
                    if ($value->type === Variable::TYPE_HASHTABLE) {
                        $fetched = $value->dimFetch($dim, $resultOp->type, $forWrite);
                        if ($forWrite) {
                            $this->context->setVariableOp($resultOp, $fetched);
                        } else {
                            $this->assignOperand($resultOp, $fetched);
                        }
                        break;
                    }
                    if ($value->type & Variable::IS_NATIVE_ARRAY && $this->context->analyzer->needsBoundsCheck($value, $dimOp)) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__nativearray__boundscheck'),
                            $dim->value,
                            $this->context->constantFromInteger($value->nextFreeElement)
                        );
                    }
                    $this->assignOperand(
                        $resultOp,
                        $value->dimFetch($dim, $resultOp->type, $forWrite)
                    );
                    break;
                case OpCode::TYPE_INIT_ARRAY:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    JIT\HashTableHelper::initArray($this->context, $result);
                    if (null !== $op->arg2) {
                        $element = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                        $key = null !== $op->arg3
                            ? $this->context->getVariableFromOp($block->getOperand($op->arg3))
                            : null;
                        JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                    }
                    break;
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $element = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $key = null !== $op->arg3
                        ? $this->context->getVariableFromOp($block->getOperand($op->arg3))
                        : null;
                    JIT\HashTableHelper::addElement($this->context, $result, $element, $key);
                    break;
                case OpCode::TYPE_TYPE_ASSERT:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->getVariableFromOp($block->getOperand($op->arg2))
                    );
                    break;
                case OpCode::TYPE_EMPTY:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $truthy = (new ext\standard\boolval())->call($this->context, $from);
                    $this->assignOperandValue(
                        $block->getOperand($op->arg1),
                        $this->context->builder->not($truthy)
                    );
                    break;
                case OpCode::TYPE_ISSET:
                    $containerOp = $block->getOperand($op->arg2);
                    $dimOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : null;
                    $container = $this->context->getVariableFromOp($containerOp);
                    $dim = null !== $dimOp ? $this->context->getVariableFromOp($dimOp) : null;
                    $issetResult = IssetHelper::compile(
                        $this->context,
                        $container,
                        $dim,
                        $dimOp,
                        $containerOp
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $issetResult);
                    break;
                case OpCode::TYPE_ITER_RESET:
                    $array = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    JIT\IteratorHelper::compileReset($this->context, $array);
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $array = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $valid = JIT\IteratorHelper::compileValid($this->context, $array);
                    $this->assignOperandValue($block->getOperand($op->arg1), $valid);
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $array = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $key = JIT\IteratorHelper::compileKey($this->context, $array);
                    $this->assignOperand($block->getOperand($op->arg1), $key);
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    if ($op->arg3) {
                        throw new \LogicException('foreach by-reference is not implemented');
                    }
                    $array = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $value = JIT\IteratorHelper::compileValue($this->context, $array);
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                    $from = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    if ($from->type === Variable::TYPE_NATIVE_BOOL) {
                        $value = $this->context->helper->loadValue($from);
                    } else {
                        $value = $this->context->castToBool($this->context->helper->loadValue($from));
                    }
                    $__right = $value->typeOf()->constInt(1, false);
                            
                        

                        

                        

                        $result = $this->context->builder->bitwiseXor($value, $__right);
    

                    $this->assignOperandValue($block->getOperand($op->arg1), $result);
                    break;
                case OpCode::TYPE_CONCAT:
                    if (!$this->context->hasVariableOp($block->getOperand($op->arg1))) {
                        // don't bother with constant operations
                        break;
                    }
                    $result = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $left = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $right = $this->context->getVariableFromOp($block->getOperand($op->arg3));
                    $this->context->type->string->concat($result, $left, $right);
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $value = null;
                    if (!is_null($op->arg3)) {
                        // try NS constant fetch
                        $value = $this->context->constantFetch($block->getOperand($op->arg3));
                    }
                    if (is_null($value)) {
                        $value = $this->context->constantFetch($block->getOperand($op->arg2));
                    }
                    if (is_null($value)) {
                        throw new \RuntimeException('Unknown constant fetch');
                    }
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;
                case OpCode::TYPE_CAST_BOOL:
                    $value = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $this->assignOperand($block->getOperand($op->arg1), $value->castTo(Variable::TYPE_NATIVE_BOOL));
                    break;
                case OpCode::TYPE_ECHO:
                case OpCode::TYPE_PRINT:
                    $argOffset = $op->type === OpCode::TYPE_ECHO ? $op->arg1 : $op->arg2;
                    $arg = $this->context->getVariableFromOp($block->getOperand($argOffset));
                    switch ($arg->type) {
                        case Variable::TYPE_VALUE:
                            JIT\ValueEchoHelper::echo($this->context, $arg->value);
                            break;
                        case Variable::TYPE_STRING:
                            if ($arg->kind === Variable::KIND_VALUE
                                && 'i8*' === $this->context->getStringFromType($arg->value->typeOf())
                            ) {
                                $byte = $this->context->builder->load($arg->value);
                                $fmt = $this->context->builder->pointerCast(
                                    $this->context->constantFromString('%c'),
                                    $this->context->getTypeFromString('char*')
                                );
                                $this->context->builder->call(
                                    $this->context->lookupFunction('printf'),
                                    $fmt,
                                    $byte
                                );
                                break;
                            }
                            $argValue = $this->context->helper->loadValue($arg);
                            $fmt = $this->context->builder->pointerCast(
                        $this->context->constantFromString("%.*s"),
                        $this->context->getTypeFromString('char*')
                    );
    $offset = $this->context->structFieldMap[$argValue->typeOf()->getElementType()->getName()]['length'];
                    $__str__length = $this->context->builder->load(
                        $this->context->builder->structGep($argValue, $offset)
                    );
    $offset = $this->context->structFieldMap[$argValue->typeOf()->getElementType()->getName()]['value'];
                    $__str__value = $this->context->builder->structGep($argValue, $offset);
    $this->context->builder->call(
                    $this->context->lookupFunction('printf') , 
                    $fmt
                    , $__str__length
                    , $__str__value
                    
                );
    
                            break;
                        case Variable::TYPE_NATIVE_LONG:
                            $argValue = $this->context->helper->loadValue($arg);
                            $fmt = $this->context->builder->pointerCast(
                        $this->context->constantFromString("%lld"),
                        $this->context->getTypeFromString('char*')
                    );
    $this->context->builder->call(
                    $this->context->lookupFunction('printf') , 
                    $fmt
                    , $argValue
                    
                );
    
                            break;
                        case Variable::TYPE_NATIVE_DOUBLE:
                            $argValue = $this->context->helper->loadValue($arg);
                            $fmt = $this->context->builder->pointerCast(
                        $this->context->constantFromString("%.14G"),
                        $this->context->getTypeFromString('char*')
                    );
    $this->context->builder->call(
                    $this->context->lookupFunction('printf') , 
                    $fmt
                    , $argValue
                    
                );
    
                            break;
                        case Variable::TYPE_NATIVE_BOOL:
                            $boolVal = $this->context->helper->loadValue($arg);
                            $charPtr = $this->context->getTypeFromString('char*');
                            $trueBlock = JIT\BasicBlockHelper::append($this->context, 'echo_bool_true');
                            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'echo_bool_done');
                            $this->context->builder->branchIf($boolVal, $trueBlock, $doneBlock);
                            $this->context->builder->positionAtEnd($trueBlock);
                            $this->context->builder->call(
                                $this->context->lookupFunction('printf'),
                                $this->context->builder->pointerCast(
                                    $this->context->constantFromString('1'),
                                    $charPtr
                                )
                            );
                            $this->context->builder->branch($doneBlock);
                            $this->context->builder->positionAtEnd($doneBlock);
                            break;

                        default: 
                            throw new \LogicException("Echo for type $arg->type not implemented");
                    }
                    if ($op->type === OpCode::TYPE_PRINT) {
                        $this->assignOperand(
                            $block->getOperand($op->arg1),
                            new Variable($this->context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $this->context->constantFromInteger(1))
                        );
                    }
                    break;
                case OpCode::TYPE_EXIT:
                    if (null === $op->arg2) {
                        $i32 = $this->context->getTypeFromString('int32');
                        $this->context->builder->call(
                            $this->context->lookupFunction('exit'),
                            $i32->constInt(0, false)
                        );
                        break;
                    }
                    $exitArg = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    JIT\Builtin\ScriptExit::emit($this->context, $exitArg);
                    break;
                case OpCode::TYPE_POW:
                    $pow = new \PHPCompiler\ext\standard\pow();
                    $powResult = $pow->call(
                        $this->context,
                        $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                        $this->context->getVariableFromOp($block->getOperand($op->arg3))
                    );
                    $this->assignOperandValue($block->getOperand($op->arg1), $powResult);
                    break;
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_IDENTICAL:
                case OpCode::TYPE_NOT_IDENTICAL:
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_SPACESHIP:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->helper->binaryOp(
                            $op,
                            $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                            $this->context->getVariableFromOp($block->getOperand($op->arg3))
                        )
                    );
                    break;
                case OpCode::TYPE_UNARY_MINUS:
                    $this->assignOperand(
                        $block->getOperand($op->arg1),
                        $this->context->helper->unaryOp(
                            $op,
                            $this->context->getVariableFromOp($block->getOperand($op->arg2)),
                        )
                    );
                    break;
                case OpCode::TYPE_CASE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $switchVar = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $caseVar = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $equalOp = new OpCode(OpCode::TYPE_EQUAL);
                    $matchVar = $this->context->helper->binaryOp($equalOp, $switchVar, $caseVar);
                    $match = $this->context->helper->loadValue($matchVar);
                    $caseBb = $this->compileBlockInternal($func, $op->block1, ...$args);
                    $nextBb = JIT\BasicBlockHelper::append($this->context, 'switch_next_case');
                    $builder->positionAtEnd($branchBlock);
                    $this->context->freeDeadVariables($func, $branchBlock, $block);
                    $builder->branchIf($match, $caseBb, $nextBb);
                    $builder->positionAtEnd($nextBb);
                    break;
                case OpCode::TYPE_JUMP:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $newBlock = $this->compileBlockInternal($func, $op->block1, ...$args);
                    $builder->positionAtEnd($branchBlock);
                    $this->context->freeDeadVariables($func, $branchBlock, $block);
                    $builder->branch($newBlock);
                    return $origBasicBlock;
                case OpCode::TYPE_COALESCE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $condition = $this->context->castToBool(
                        $this->context->helper->loadValue($this->context->getVariableFromOp($block->getOperand($op->arg2)))
                    );
                    $leftBb = JIT\CoalesceHelper::compileBranch($this, $func, $op->block1);
                    $leftTail = $builder->getInsertBlock();
                    $rightBb = JIT\CoalesceHelper::compileBranch($this, $func, $op->block2);
                    $rightTail = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $this->context->freeDeadVariables($func, $branchBlock, $block);
                    $builder->branchIf($condition, $leftBb, $rightBb);
                    if (null !== $op->block3) {
                        $mergeBb = JIT\BasicBlockHelper::append($this->context, 'coalesce_merge');
                        $builder->positionAtEnd($leftTail);
                        $builder->branch($mergeBb);
                        $builder->positionAtEnd($rightTail);
                        $builder->branch($mergeBb);
                        $builder->positionAtEnd($mergeBb);

                        return $this->compileBlockInternal($func, $op->block3, null, $mergeBb, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_NULLSAFE:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $receiver = $this->context->getVariableFromOp($block->getOperand($op->arg2));
                    $valuePtr = JIT\Variable::KIND_VARIABLE === $receiver->kind
                        ? $receiver->value
                        : $this->context->helper->loadValue($receiver);
                    $typeByte = $this->context->builder->load(
                        $this->context->builder->structGep(
                            $valuePtr,
                            $this->context->structFieldMap['__value__']['type']
                        )
                    );
                    $i8 = $this->context->getTypeFromString('int8');
                    $isNull = $this->context->builder->icmp(
                        \PHPLLVM\Builder::INT_EQ,
                        $typeByte,
                        $i8->constInt(JIT\Variable::TYPE_NULL, false)
                    );
                    $nullBb = JIT\NullsafeHelper::compileBranch($this, $func, $op->block1);
                    $fetchBb = JIT\NullsafeHelper::compileBranch($this, $func, $op->block2);
                    $builder->positionAtEnd($branchBlock);
                    $this->context->freeDeadVariables($func, $branchBlock, $block);
                    $builder->branchIf($isNull, $nullBb, $fetchBb);
                    if (null !== $op->block3) {
                        $mergeBb = JIT\BasicBlockHelper::append($this->context, 'nullsafe_merge');
                        $builder->positionAtEnd($nullBb);
                        $builder->branch($mergeBb);
                        $builder->positionAtEnd($fetchBb);
                        $builder->branch($mergeBb);
                        $builder->positionAtEnd($mergeBb);

                        return $this->compileBlockInternal($func, $op->block3, null, $mergeBb, ...$args);
                    }

                    return $origBasicBlock;
                case OpCode::TYPE_JUMPIF:
                    $branchBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($branchBlock);
                    $condition = $this->context->castToBool(
                        $this->context->helper->loadValue($this->context->getVariableFromOp($block->getOperand($op->arg1)))
                    );
                    $if = $this->compileBlockInternal($func, $op->block1, ...$args);
                    $else = $this->compileBlockInternal($func, $op->block2, ...$args);
                    $builder->positionAtEnd($branchBlock);
                    $this->context->freeDeadVariables($func, $branchBlock, $block);
                    $builder->branchIf($condition, $if, $else);
                    return $origBasicBlock;
                case OpCode::TYPE_RETURN_VOID:
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    $this->context->freeDeadVariables($func, $returnBlock, $block);
                    $this->context->builder->returnVoid();
    
                    return $origBasicBlock;
                case OpCode::TYPE_RETURN:
                    $return = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    $return->addref();
                    $retval = $this->context->helper->loadValue($return);
                    $returnBlock = $builder->getInsertBlock();
                    $builder->positionAtEnd($returnBlock);
                    $this->context->freeDeadVariables($func, $returnBlock, $block);
                    $this->context->builder->returnValue($retval);
    
                    return $origBasicBlock;
                case OpCode::TYPE_FUNCDEF:
                    $nameOp = $block->getOperand($op->arg1);
                    assert($nameOp instanceof Operand\Literal);
                    $this->compileBlock($op->block1, $nameOp->value);
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $nameOp = $block->getOperand($op->arg1);
                    if (!$nameOp instanceof Operand\Literal) {
                        throw new \LogicException("Variable function calls not yet supported");
                    }
                    $lcname = strtolower($nameOp->value);
                    if (isset($this->context->functionProxies[$lcname])) {
                        $this->context->scope->toCall = $this->context->functionProxies[$lcname];
                    } else {
                        throw new \RuntimeException("Call to undefined function $lcname");
                    }
                    $this->context->scope->args = [];
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $this->context->scope->args[] = $this->context->getVariableFromOp($block->getOperand($op->arg1));
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    if (is_null($this->context->scope->toCall)) {
                        // short circuit
                        break;
                    }
                    $this->context->scope->toCall->call($this->context, ...$this->context->scope->args);
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                    $result = $this->context->scope->toCall->call($this->context, ...$this->context->scope->args);
                    $this->assignOperandValue($block->getOperand($op->arg1), $result);
                    break;
                // case OpCode::TYPE_DECLARE_CLASS:
                //     $this->context->pushScope();
                //     $this->context->scope->classId = $this->context->type->object->declareClass($block->getOperand($op->arg1));
                //     $this->compileClass($op->block1, $this->context->scope->classId);
                //     $this->context->popScope();
                //     break;
                // case OpCode::TYPE_NEW:
                //     $class = $this->context->type->object->lookupOperand($block->getOperand($op->arg2));
                //     $this->context->helper->assign(
                //         $gccBlock,
                //         $this->context->getVariableFromOp($block->getOperand($op->arg1))->lvalue,
                //         $this->context->type->object->allocate($class)
                //     );
                //     $this->context->scope->toCall = null;
                //     $this->context->scope->args = [];
                //     break;
                // case OpCode::TYPE_PROPERTY_FETCH:
                //     $result = $block->getOperand($op->arg1);
                //     $obj = $block->getOperand($op->arg2);
                //     $name = $block->getOperand($op->arg3);
                //     assert($name instanceof Operand\Literal);
                //     assert($obj->type->type === Type::TYPE_OBJECT);
                //     $this->context->scope->variables[$result] = $this->context->type->object->propertyFetch(
                //         $this->context->getVariableFromOp($obj)->rvalue,
                //         $obj->type->userType,
                //         $name->value
                //     );
                //     break;
                default:
                    throw new \LogicException("Unknown JIT opcode: ". $op->getType());
            }
        }

        return $builder->getInsertBlock();
    }

    private function compileClass(?Block $block, int $classId) {
        if ($block === null) {
            return;
        }
        foreach ($block->opCodes as $op) {
            switch ($op->type) {
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $name = $block->getOperand($op->arg1);
                    assert($name instanceof Operand\Literal);
                    assert(is_null($op->arg2)); // no defaults for now
                    $type = Variable::getTypeFromType($block->getOperand($op->arg3)->type);
                    $this->context->type->object->defineProperty($classId, $name->value, $type);
                    break;
                default:
                    var_dump($op);
                    throw new \LogicException('Other class body types are not jittable for now');
            }
            
        }
    }

    private function assignOperand(Operand $result, Variable $value): void {
        if (empty($result->usages) && !$this->context->scope->variables->contains($result)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            // it's a kind!
            $this->context->makeVariableFromValueOp($this->context->helper->loadValue($value), $result);
            return;
        }
        $result = $this->context->getVariableFromOp($result);
        if ($result->kind === Variable::KIND_VALUE && $result->type === Variable::TYPE_STRING) {
            StringOffsetHelper::dimAssign($this->context, $result->value, $value);

            return;
        }
        if ($result->kind !== Variable::KIND_VARIABLE) {
            throw new \LogicException("Cannot assign to a value");
        }
        if ($value->type === $result->type) {
            $result->free();
            if ($value->type & Variable::IS_NATIVE_ARRAY || Variable::TYPE_HASHTABLE === $value->type) {
                $result->nextFreeElement = $value->nextFreeElement;
            }
            $this->context->builder->store(
                $this->context->helper->loadValue($value),
                $result->value
            );
            $result->addref();
            return;
        } elseif ($result->type === Variable::TYPE_VALUE) {
            // wrap
            $valueRef = $result->value;
            $valueFrom = $value->value;
            switch ($value->type) {
                case Variable::TYPE_NULL:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull') , 
                    $valueRef
                    
                );
    
                    return;
                case Variable::TYPE_NATIVE_LONG:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong') , 
                    $valueRef
                    , $valueFrom
                    
                );
    
                    return;
                case Variable::TYPE_NATIVE_DOUBLE:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble') , 
                    $valueRef
                    , $valueFrom
                    
                );
    
                    return;
                case Variable::TYPE_NATIVE_BOOL:
                    JIT\JitValueBox::writeBool(
                        $this->context,
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );

                    return;
                case Variable::TYPE_STRING:
                    $str = $this->context->helper->loadValue($value);
                    $owned = $this->context->builder->call(
                        $this->context->lookupFunction('__string__separate'),
                        $str
                    );
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyString'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $owned
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeString'),
                        $valueRef,
                        $owned
                    );

                    return;
                case Variable::TYPE_HASHTABLE:
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeHashtable'),
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );

                    return;
                case Variable::TYPE_VALUE:
                    JIT\JitValueBox::copyFromPointer(
                        $this->context,
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );

                    return;
                default:
                    throw new \LogicException("Source type: {$value->type}");
            }
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_VALUE === $value->type) {
            $longVal = $this->context->builder->call(
                $this->context->lookupFunction('__value__readLong'),
                $this->context->helper->loadValue($value)
            );
            $this->context->builder->store($longVal, $result->value);

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $result->free();
            $fp = $this->context->helper->loadValue($value);
            $long = $this->context->builder->fpToSi($fp, $this->context->getTypeFromString('int64'));
            $this->context->builder->store($long, $result->value);
            $result->addref();

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_DOUBLE && Variable::TYPE_NATIVE_LONG === $value->type) {
            $result->free();
            $long = $this->context->helper->loadValue($value);
            $fp = $this->context->builder->siToFp($long, $this->context->getTypeFromString('double'));
            $this->context->builder->store($fp, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_VALUE === $result->type && Variable::TYPE_VALUE === $value->type) {
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $result->value,
                $this->context->helper->loadValue($value)
            );

            return;
        } elseif (Variable::TYPE_HASHTABLE === $result->type && Variable::TYPE_VALUE === $value->type) {
            $valuePtr = $this->context->helper->loadValue($value);
            $ht = $this->context->builder->call(
                $this->context->lookupFunction('__value__readHashtable'),
                $valuePtr
            );
            $result->free();
            $this->context->builder->store($ht, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_VALUE === $value->type) {
            $valuePtr = $this->context->helper->loadValue($value);
            $str = $this->context->builder->call(
                $this->context->lookupFunction('__value__readString'),
                $valuePtr
            );
            $result->free();
            $this->context->builder->store($str, $result->value);
            $result->addref();

            return;
        }
        throw new \LogicException("Cannot assign operands of different types (yet): {$value->type}, {$result->type}");
    }

    private function assignOperandValue(Operand $result, PHPLLVM\Value $value): void {
        if (empty($result->usages) && !$this->context->scope->variables->contains($result)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            $this->context->makeVariableFromValueOp($value, $result);

            return;
        }
        $dest = $this->context->getVariableFromOp($result);
        if ($dest->kind !== Variable::KIND_VARIABLE) {
            throw new \LogicException('Cannot assign to a value');
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

            return;
        }
        $this->assignOperand($result, $source);
    }

    private function jitTypeFromLlvmValue(PHPLLVM\Value $value): int
    {
        switch ($this->context->getStringFromType($value->typeOf())) {
            case 'double':
                return Variable::TYPE_NATIVE_DOUBLE;
            case 'int1':
            case 'bool':
                return Variable::TYPE_NATIVE_BOOL;
            case 'int64':
            case 'long long':
            case 'int32':
            case 'size_t':
            case 'unsigned int':
                return Variable::TYPE_NATIVE_LONG;
            case '__string__*':
                return Variable::TYPE_STRING;
            case '__hashtable__*':
                return Variable::TYPE_HASHTABLE;
            case '__value__*':
                return Variable::TYPE_VALUE;
            default:
                throw new \LogicException(
                    'Cannot infer JIT variable type from LLVM type: '
                    .$this->context->getStringFromType($value->typeOf())
                );
        }
    }

    /**
     * @return array<int, Variable>
     */
    private function collectParamDefaults(Block $block): array {
        $defaults = [];
        foreach ($block->opCodes as $op) {
            if ($op->type !== OpCode::TYPE_ARG_RECV || null === $op->arg3) {
                continue;
            }
            if (!isset($block->constants[$op->arg3])) {
                continue;
            }
            $defaults[$op->arg2] = $this->jitVariableFromVmConstant($block->constants[$op->arg3]);
        }
        return $defaults;
    }

    private function jitVariableFromVmConstant(VM\Variable $vm): Variable {
        switch ($vm->type) {
            case VM\Variable::TYPE_INTEGER:
                return Variable::fromConstantInt($this->context, $vm->toInt());
            case VM\Variable::TYPE_STRING:
                $lit = new Operand\Literal($vm->toString());
                $lit->type = Type::string();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_FLOAT:
                $lit = new Operand\Literal($vm->toFloat());
                $lit->type = Type::float();
                return Variable::fromLiteral($this->context, $lit);
            case VM\Variable::TYPE_BOOLEAN:
                $lit = new Operand\Literal($vm->toBool());
                $lit->type = Type::bool();
                return Variable::fromLiteral($this->context, $lit);
            default:
                throw new \LogicException('Unsupported default parameter type for JIT');
        }
    }

}
