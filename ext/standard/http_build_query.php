<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
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

        VmArray::requireArrayParam($frame->calledArgs[0], 'http_build_query', 1, 'data');
        $data = $frame->calledArgs[0]->resolveIndirect();

        $prefix = self::resolveOptionalStringArg($frame->calledArgs, 1, 'numeric_prefix', '');
        [$separator, $encoding, $legacyEncoding] = self::resolveSeparatorAndEncoding($frame->calledArgs);

        $exported = VmHttpBuildQuery::export($data, $frame);
        if (!\is_array($exported)) {
            throw new \LogicException('http_build_query() argument #1 must be an array in this compiler build');
        }
        $frame->returnVar->string(
            VmHttpBuildQuery::build($exported, $prefix, $separator, $encoding, $legacyEncoding)
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
     * php-src ext/standard/http.c — legacy 3-arg int encoding_type vs 4-arg separator+encoding.
     *
     * @param array<int, \PHPCompiler\VM\Variable> $args
     *
     * @return array{0: string, 1: int, 2: bool}
     */
    private static function resolveSeparatorAndEncoding(array $args): array
    {
        $separator = '&';
        $encoding = VmHttpBuildQuery::ENCODING_RFC1738;
        $legacyEncoding = false;

        if (!\array_key_exists(2, $args)) {
            return [$separator, $encoding, $legacyEncoding];
        }

        $var3 = $args[2]->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var3->type) {
            return [$separator, $var3->toInt(), true];
        }

        if (Variable::TYPE_NULL === $var3->type) {
            $separator = '&';
        } elseif (Variable::TYPE_STRING === $var3->type) {
            $separator = $var3->toString();
        } else {
            throw new \LogicException(
                'http_build_query() argument #3 ($arg_separator) must be a string in this compiler build'
            );
        }

        if (\array_key_exists(3, $args)) {
            $var4 = $args[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $var4->type) {
                throw new \LogicException(
                    'http_build_query() argument #4 ($encoding_type) must be an integer in this compiler build'
                );
            }
            $encoding = $var4->toInt();
        }

        return [$separator, $encoding, $legacyEncoding];
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('http_build_query() requires at least one argument');
        }
        if (\count($args) > 4) {
            throw new \LogicException('http_build_query() accepts at most four arguments in this compiler build');
        }

        TypeErrorRaise::ensureLinked($context);
        JitArrayElem::requireArrayParam($context, $args[0], 'http_build_query', 1, 'data');

        $data = $args[0];
        $prefix = $this->optionalStringArg($context, $args, 1, '');
        [$separator, $encoding] = $this->resolveSeparatorAndEncodingJit($context, $args);

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

    /**
     * @param array<int, JITVariable> $args
     *
     * @return array{0: Value, 1: Value}
     */
    private function resolveSeparatorAndEncodingJit(Context $context, array $args): array
    {
        $i64 = $context->getTypeFromString('int64');
        $defaultSeparator = $context->builder->load($context->constantStringFromString('&'));
        $defaultEncoding = $i64->constInt(VmHttpBuildQuery::ENCODING_RFC1738, false);

        if (!isset($args[2])) {
            return [$defaultSeparator, $defaultEncoding];
        }

        $arg3 = $args[2];
        if (JITVariable::TYPE_NATIVE_LONG === $arg3->type) {
            // Legacy 3-arg int encoding: RFC3986 raw mode is not enabled (php-src http.c BC).
            return [$defaultSeparator, $i64->constInt(VmHttpBuildQuery::ENCODING_RFC1738, false)];
        }

        if (JITVariable::TYPE_NULL === $arg3->type) {
            $separator = $defaultSeparator;
        } else {
            $separator = $this->jitString($context, $arg3, 'http_build_query() argument #3');
        }

        return [$separator, $this->optionalEncodingArg($context, $args, 3)];
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
