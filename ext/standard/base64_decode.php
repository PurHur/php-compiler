<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringBase64Decode;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** base64_decode() — RFC 4648 decode with optional $strict (php-src ext/standard/base64.c). */
final class base64_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('base64_decode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('base64_decode() requires one or two arguments in this compiler build');
        }
        $data = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'base64_decode', 0, 'string');
        $strict = false;
        if (2 === $argc) {
            $strictVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $strictVar->type) {
                throw new \LogicException('base64_decode() argument #2 ($strict) must be a boolean in this compiler build');
            }
            $strict = $strictVar->toBool();
        }
        $result = VmString::base64_decode($data, $strict);
        BuiltinExecute::writeReturn($frame, static function ($ret) use ($result): void {
            if (false === $result) {
                $ret->bool(false);
            } else {
                $ret->string($result);
            }
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('base64_decode() requires one or two arguments in this compiler build');
        }
        $strict = null;
        $strictConst = false;
        if (2 === $argc) {
            $strict = $this->jitBool($context, $args[1], 'base64_decode() argument #2 ($strict)');
            $ct = $args[1]->compileTimeBool ?? null;
            if (null !== $ct) {
                $strictConst = (bool) $ct;
            }
        }
        $literal = null;
        if (JITVariable::TYPE_VALUE !== $args[0]->type) {
            $maybeLiteral = $args[0]->compileTimeString ?? null;
            if (null !== $maybeLiteral && JITVariable::KIND_VALUE === $args[0]->kind) {
                $literal = $maybeLiteral;
            }
        }
        if (null !== $literal && (1 === $argc || null !== ($args[1]->compileTimeBool ?? null))) {
            $result = VmString::base64_decode($literal, $strictConst);
            if (false === $result) {
                return $context->constantFromBool(false);
            }

            return $context->builder->load(
                $context->constantStringFromString($result)
            );
        }

        StringBase64Decode::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_base64_decode'),
            JitStringBuiltinArg::lower($context, $args[0], 'base64_decode', 0, 'string')
        );
    }
}
