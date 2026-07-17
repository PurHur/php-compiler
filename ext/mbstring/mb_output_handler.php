<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mb_output_handler() — OB callback converting to mb_http_output encoding
 * (php-src ext/mbstring/mbstring.c; #20014).
 */
final class mb_output_handler extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_output_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(sprintf(
                'mb_output_handler() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $string = VmMbstring::coerceOutputHandlerStringArg(
            $frame->calledArgs[0],
            'mb_output_handler',
            0
        );
        $status = VmMbstring::coerceOutputHandlerStatusArg(
            $frame->calledArgs[1],
            'mb_output_handler',
            1
        );
        $frame->returnVar->string(VmMbstring::outputHandler($string, $status));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \ArgumentCountError(sprintf(
                'mb_output_handler() expects exactly 2 arguments, %d given',
                \count($args)
            ));
        }

        $stringLit = JitStringArg::compileTimeLiteral($args[0]);
        $statusLit = self::compileTimeStatus($context, $args[1]);
        if (null === $stringLit || null === $statusLit) {
            throw new \LogicException(
                'mb_output_handler() requires compile-time string and int arguments in this compiler build'
            );
        }
        $out = self::foldOutputHandler($context, $stringLit, $statusLit);

        return $context->builder->load($context->constantStringFromString($out));
    }

    private static function foldOutputHandler(Context $context, string $string, int $status): string
    {
        $httpOutput = MbstringAotFoldState::httpOutput($context) ?? (string) MbstringState::httpOutput();
        if (0 === strcasecmp($httpOutput, 'pass')) {
            return $string;
        }
        $from = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
        if (0 === strcasecmp($from, $httpOutput)) {
            return $string;
        }
        $converted = VmMbstring::convertEncoding($string, $httpOutput, $from);
        if (false === $converted) {
            return $string;
        }

        return $converted;
    }

    private static function compileTimeStatus(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
            }
        }
        $constName = $arg->compileTimeConstantName ?? null;
        if (null !== $constName && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($constName);
            if (null !== $phpVar && \PHPCompiler\VM\Variable::TYPE_INTEGER === $phpVar->type) {
                return $phpVar->toInt();
            }
        }

        return null;
    }
}
