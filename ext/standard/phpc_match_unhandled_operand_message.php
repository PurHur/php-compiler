<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\MatchUnhandledJitHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\MatchUnhandledSupport;
use PHPLLVM\Value;

/**
 * Match lowering helper — full UnhandledMatchError message (#23664).
 *
 * php-src: Zend/zend_execute.c — zend_match_unhandled_error()
 * SSOT: {@see MatchUnhandledSupport}
 */
final class phpc_match_unhandled_operand_message extends Internal
{
    public function __construct()
    {
        parent::__construct('phpc_match_unhandled_operand_message');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_match_unhandled_operand_message() requires exactly one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(
            MatchUnhandledSupport::formatMessage($frame->calledArgs[0])
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('phpc_match_unhandled_operand_message() requires exactly one argument');
        }
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            // Enums: Enum::Case via smart_str_append_zval; other objects: of type Class (#29248).
            return MatchUnhandledJitHelper::formatObjectOrEnumCaseMessage($context, $args[0]);
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type
            || 0 !== ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            return $context->builder->load(
                $context->constantStringFromString('Unhandled match case of type array')
            );
        }
        $fmt = new phpc_match_unhandled_format_scalar();
        $suffix = $fmt->call($context, ...$args);
        $prefix = $context->builder->load(
            $context->constantStringFromString('Unhandled match case ')
        );

        return JitStringConcat::concat($context, $prefix, $suffix);
    }
}
