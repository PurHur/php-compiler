<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitOperandTypeLabel;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * settype() — cast variable in place by type name (ext/standard/type.c).
 *
 * VM + JIT in-place casts (ext/standard/type.c; JIT #3151).
 */
final class settype extends Internal
{
    public function __construct()
    {
        parent::__construct('settype');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/type.c — ArgumentCountError (#21964).
        $this->requireExactArgCount($frame, 'settype', 2);
        $slot = $frame->calledArgs[0];
        // Z_PARAM_STR: soft-null DEP+coerce outside strict_types, then valid-type table
        // (#30506); objects still TypeError with concrete class (#25724, zend_API.c).
        $typeName = VmString::stringBuiltinArgForFrame($frame, 1, 'settype', 1, 'type', false);
        VmSettype::apply($slot, $typeName, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, 'settype', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $typeArg = $args[1];
        $typeIsNull = JITVariable::TYPE_NULL === $typeArg->type || ($typeArg->isNullConstant ?? false);
        // Soft-null outside strict_types → Deprecated then ValueError (empty type); strict → TypeError (#30506).
        if ($typeIsNull) {
            if ($context->callerStrictTypes) {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    'settype(): Argument #2 ($type) must be of type string, null given'
                );
            } else {
                JitStringBuiltinArg::emitNullStringParamDeprecation($context, 'settype', 1, 'type');
                ExceptionBridge::emitValueErrorAndAbort(
                    $context,
                    'settype(): Argument #2 ($type) must be a valid type'
                );
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'settype_null_type_cont');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        // Reject non-string type names with Zend-shaped TypeError before literal lowering (#25724).
        if (JITVariable::TYPE_STRING !== $typeArg->type) {
            if (JITVariable::TYPE_OBJECT === $typeArg->type) {
                $objectGiven = JitOperandTypeLabel::givenLabel($context, $typeArg);
                if ('object' === $objectGiven || 'mixed' === $objectGiven) {
                    JitStringBuiltinArg::emitObjectTypeErrorReject(
                        $context,
                        $typeArg,
                        'settype',
                        1,
                        'type',
                        'string'
                    );
                } else {
                    ExceptionBridge::emitTypeErrorAndAbort(
                        $context,
                        sprintf(
                            'settype(): Argument #2 ($type) must be of type string, %s given',
                            $objectGiven
                        )
                    );
                }
            } else {
                ExceptionBridge::emitTypeErrorAndAbort(
                    $context,
                    sprintf(
                        'settype(): Argument #2 ($type) must be of type string, %s given',
                        JitOperandTypeLabel::givenLabel($context, $typeArg)
                    )
                );
            }
            // Catchable throw terminates the block — keep insert open for the call return (#22827).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'settype_type_te_cont');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitSettype::invoke($context, $args[0], $typeArg);
    }
}