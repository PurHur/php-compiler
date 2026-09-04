<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\ext\standard\VmEngineBuiltinDeprecation;
use PHPCompiler\Frame;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * libxml_disable_entity_loader() — toggle external entity expansion (#6379, #36382).
 *
 * php-src: ext/libxml/libxml.c — PHP_FUNCTION(libxml_disable_entity_loader) (deprecated PHP 8.0+)
 */
final class libxml_disable_entity_loader extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_disable_entity_loader');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'libxml_disable_entity_loader() expects at most 1 argument, '.$argc.' given'
            );
        }
        VmEngineBuiltinDeprecation::emitFunction($frame, 'libxml_disable_entity_loader');
        $disable = true;
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $arg->type) {
                $disable = $arg->toBool();
            } else {
                $disable = (bool) $arg->toInt();
            }
        }
        if (null === $frame->returnVar) {
            VmLibxml::disableEntityLoader($disable);

            return;
        }
        $frame->returnVar->bool(VmLibxml::disableEntityLoader($disable));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Slim BodyParsingMiddleware compiles the libxml<2.9 dead branch (#36382).
        // libxml ≥ 2.9 disables entity loading by default — soft-return previous=true.
        BasicBlockHelper::ensureOpenInsertBlock($context, 'libxml_disable_entity_loader');
        $argc = \count($args);
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'libxml_disable_entity_loader() expects at most 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        if (1 === $argc && JITVariable::TYPE_NULL !== $args[0]->type && !$args[0]->isNullConstant) {
            JitBoolArg::lower(
                $context,
                $args[0],
                'libxml_disable_entity_loader(): Argument #1 ($disable)'
            );
        }

        $true = $context->getTypeFromString('int1')->constInt(1, false);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return $true;
        }
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $true);

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
