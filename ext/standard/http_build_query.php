<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringHttpBuildQuery;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        // php-src ext/standard/basic_functions.stub.php — ArgumentCountError (#28691).
        $this->requireArgCountRange($frame, 'http_build_query', 1, 4);
        if (null === $frame->returnVar) {
            return;
        }

        // php-src Z_PARAM_ARRAY_OR_OBJECT — TypeError text still says "array" on 8.2 (#21950).
        $data = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $data->type && Variable::TYPE_OBJECT !== $data->type
            && Variable::TYPE_ENUM_CASE !== $data->type) {
            VmArray::requireArrayParam($frame->calledArgs[0], 'http_build_query', 1, 'data');
        }
        VmHttpBuildQuery::rejectRootEnumIfNeeded($data);

        // php-src Z_PARAM_STR $numeric_prefix (default ""): explicit null → E_DEPRECATED + "" (#29721).
        // Caller strict_types → TypeError. Omitted arg keeps "".
        $prefix = '';
        if (\array_key_exists(1, $frame->calledArgs)) {
            $prefix = VmString::stringBuiltinArgForFrame(
                $frame,
                1,
                'http_build_query',
                1,
                'numeric_prefix',
                false
            );
        }
        [$separator, $encoding, $legacyEncoding] = self::resolveSeparatorAndEncoding($frame);

        $exported = VmHttpBuildQuery::export($data, $frame);
        if (!\is_array($exported)) {
            throw new \LogicException('http_build_query() argument #1 must be array|object in this compiler build');
        }
        $frame->returnVar->string(
            VmHttpBuildQuery::build($exported, $prefix, $separator, $encoding, $legacyEncoding)
        );
    }

    /**
     * php-src ext/standard/http.c — legacy 3-arg int encoding_type vs 4-arg separator+encoding.
     *
     * $encoding_type is Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31247).
     *
     * @return array{0: string, 1: int, 2: bool}
     */
    private static function resolveSeparatorAndEncoding(Frame $frame): array
    {
        $args = $frame->calledArgs;
        $separator = '&';
        $encoding = VmHttpBuildQuery::ENCODING_RFC1738;
        $legacyEncoding = false;

        if (!\array_key_exists(2, $args)) {
            if (\array_key_exists(3, $args)) {
                return [
                    $separator,
                    VmMath::parseZParamLongBuiltinArgForFrame(
                        $frame,
                        3,
                        'http_build_query',
                        4,
                        'encoding_type'
                    ),
                    $legacyEncoding,
                ];
            }

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
            $encoding = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                3,
                'http_build_query',
                4,
                'encoding_type'
            );
        }

        return [$separator, $encoding, $legacyEncoding];
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28691.
        if (!$this->requireArgCountRangeJit($context, $args, 'http_build_query', 1, 4)) {
            return $context->builder->load($context->constantStringFromString(''));
        }

        TypeErrorRaise::ensureLinked($context);
        // Peer preg_quote (#26827): thin AOT needs call-site ensureLinked (#26869).
        StringHttpBuildQuery::ensureLinked($context);
        // Soft-null outside strict_types; strict → TypeError (#31247).
        // Early return after compile-time null TypeError — open a dead insert block so the
        // call site can lower a discarded return without mid-block terminator (AOT verify;
        // peer metaphone #31230 / setcookie #31229).
        if (isset($args[3])
            && $context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[3], 'http_build_query', 4, 'encoding_type');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'http_build_query_null_encoding_te_cont');

            return $context->builder->load($context->constantStringFromString(''));
        }
        $data = JitHttpBuildQuery::normalizeDataArg($context, $args[0]);
        // Soft-null DEP+coerce; strict_types TypeError (#29721; peer basename #29705).
        if (isset($args[1])) {
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitStringBuiltinArg::lowerStrictOrCoercible(
                    $context,
                    $args[1],
                    'http_build_query',
                    1,
                    'numeric_prefix',
                    'string',
                    null,
                    false
                );
                BasicBlockHelper::ensureOpenInsertBlock($context, 'http_build_query_null_prefix_te_cont');

                return $context->builder->load($context->constantStringFromString(''));
            }
            $prefix = JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $args[1],
                'http_build_query',
                1,
                'numeric_prefix',
                'string',
                null,
                false
            );
        } else {
            $prefix = $context->builder->load($context->constantStringFromString(''));
        }
        [$separator, $encoding] = $this->resolveSeparatorAndEncodingJit($context, $args);

        return JitHttpBuildQuery::build($context, $data, $prefix, $separator, $encoding);
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
            return [$defaultSeparator, $this->optionalEncodingArg($context, $args, 3)];
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

        // Z_PARAM_LONG with caller strict_types parity (#31247).
        return JitIntdiv::lowerIntBuiltinArgForCaller(
            $context,
            $arg,
            'http_build_query',
            $index + 1,
            'encoding_type'
        );
    }
}
