<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * http_build_query() — query string builder (VM via VmHttpBuildQuery; JIT/AOT via HttpBuildQueryJitHelper PHP).
 */
final class http_build_query extends Internal
{
    public function __construct()
    {
        parent::__construct('http_build_query');
    }

    public function execute(Frame $frame): void
    {
        if (!\array_key_exists(0, $frame->calledArgs)) {
            throw new \LogicException('http_build_query() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (\count($frame->calledArgs) > 4) {
            throw new \LogicException('http_build_query() accepts at most four arguments in this compiler build');
        }

        $data = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $data->type) {
            throw new \LogicException('http_build_query() argument #1 must be an array in this compiler build');
        }

        $prefix = self::resolveOptionalStringArg($frame->calledArgs, 1, 'numeric_prefix', '');
        $separator = self::resolveOptionalSeparatorArg($frame->calledArgs);
        $encoding = self::resolveOptionalEncodingArg($frame->calledArgs);

        $exported = VmHttpBuildQuery::export($data, $frame);
        if (!\is_array($exported)) {
            throw new \LogicException('http_build_query() argument #1 must be an array in this compiler build');
        }
        $frame->returnVar->string(
            VmHttpBuildQuery::build($exported, $prefix, $separator, $encoding)
        );
    }

    /**
     * @param array<int, \PHPCompiler\VM\Variable> $args
     */
    private static function resolveOptionalStringArg(array $args, int $index, string $paramName, string $default): string
    {
        if (!\array_key_exists($index, $args)) {
            return $default;
        }
        $var = $args[$index]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return $default;
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \LogicException(
                'http_build_query() argument #'.($index + 1).' ($'.$paramName.') must be a string in this compiler build'
            );
        }

        return $var->toString();
    }

    /**
     * @param array<int, \PHPCompiler\VM\Variable> $args
     */
    private static function resolveOptionalSeparatorArg(array $args): string
    {
        if (!\array_key_exists(2, $args)) {
            return '&';
        }
        $var = $args[2]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return '&';
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \LogicException(
                'http_build_query() argument #3 ($arg_separator) must be a string in this compiler build'
            );
        }

        return $var->toString();
    }

    /**
     * @param array<int, \PHPCompiler\VM\Variable> $args
     */
    private static function resolveOptionalEncodingArg(array $args): int
    {
        if (!\array_key_exists(3, $args)) {
            return VmHttpBuildQuery::ENCODING_RFC1738;
        }
        $var = $args[3]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException(
                'http_build_query() argument #4 ($encoding_type) must be an integer in this compiler build'
            );
        }

        return $var->toInt();
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('http_build_query() requires at least one argument');
        }
        if (\count($args) > 4) {
            throw new \LogicException('http_build_query() accepts at most four arguments in this compiler build');
        }

        $data = $args[0];
        $prefix = $this->optionalStringArg($context, $args, 1, '');
        $separator = $this->optionalStringArg($context, $args, 2, '&');
        $encoding = $this->optionalEncodingArg($context, $args, 3);

        return JitHttpBuildQuery::build($context, $data, $prefix, $separator, $encoding);
    }

    private function optionalStringArg(Context $context, array $args, int $index, string $default): Value
    {
        if (!isset($args[$index])) {
            return $context->builder->load($context->constantStringFromString($default));
        }
        $arg = $args[$index];
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $context->builder->load($context->constantStringFromString($default));
        }

        return $this->jitString($context, $arg, 'http_build_query() argument #'.($index + 1));
    }

    private function optionalEncodingArg(Context $context, array $args, int $index): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (!isset($args[$index])) {
            return $i64->constInt(VmHttpBuildQuery::ENCODING_RFC1738, false);
        }

        $arg = $args[$index];
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && null !== $arg->value) {
            return $arg->value;
        }

        return JitLongArg::lower($context, $arg, 'http_build_query() argument #'.($index + 1));
    }
}
