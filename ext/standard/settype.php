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
        // Z_PARAM_STR — include ", <Type> given" with concrete class (#25724, zend_API.c).
        $typeName = VmString::requireStringBuiltinArg($frame->calledArgs[1], 'settype', 1, 'type');
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
        // Reject non-string type names with Zend-shaped TypeError before literal lowering (#25724).
        if (JITVariable::TYPE_STRING !== $args[1]->type) {
            if (JITVariable::TYPE_OBJECT === $args[1]->type) {
                $objectGiven = JitOperandTypeLabel::givenLabel($context, $args[1]);
                if ('object' === $objectGiven || 'mixed' === $objectGiven) {
                    JitStringBuiltinArg::emitObjectTypeErrorReject(
                        $context,
                        $args[1],
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
                        JitOperandTypeLabel::givenLabel($context, $args[1])
                    )
                );
            }
            // Catchable throw terminates the block — keep insert open for the call return (#22827).
            BasicBlockHelper::ensureOpenInsertBlock($context, 'settype_type_te_cont');

            return $context->getTypeFromString('int1')->constInt(0, false);
        }

        return JitSettype::invoke($context, $args[0], $args[1]);
    }
}