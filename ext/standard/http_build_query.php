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
 * http_build_query() — query string builder (subset; VM via PHP; JIT/AOT via __compiler_http_build_query).
 */
final class http_build_query extends Internal
{
    public function __construct()
    {
        parent::__construct('http_build_query');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('http_build_query() requires at least one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        if ($argc > 4) {
            throw new \LogicException('http_build_query() accepts at most four arguments in this compiler build');
        }

        $data = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $data->type) {
            throw new \LogicException('http_build_query() argument #1 must be an array in this compiler build');
        }

        $prefix = '';
        if ($argc >= 2) {
            $prefixVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_STRING !== $prefixVar->type) {
                throw new \LogicException('http_build_query() argument #2 must be a string in this compiler build');
            }
            $prefix = $prefixVar->toString();
        }

        $separator = '&';
        if ($argc >= 3) {
            $sepVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_STRING !== $sepVar->type) {
                throw new \LogicException('http_build_query() argument #3 must be a string in this compiler build');
            }
            $separator = $sepVar->toString();
        }

        $encoding = VmHttpBuildQuery::ENCODING_RFC1738;
        if ($argc >= 4) {
            $encVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $encVar->type) {
                throw new \LogicException('http_build_query() argument #4 must be an integer in this compiler build');
            }
            $encoding = $encVar->toInt();
        }

        $exported = VmHttpBuildQuery::export($data);
        $built = \http_build_query($exported, $prefix, $separator, $encoding);
        if (false === $built) {
            throw new \LogicException('http_build_query() failed');
        }
        $frame->returnVar->string($built);
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

        return $this->jitString($context, $args[$index], 'http_build_query() argument #'.($index + 1));
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
