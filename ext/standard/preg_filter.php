<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * preg_filter() — filter/replace with PCRE; only matched entries returned (ext/standard/pcre.c; #3250).
 *
 * VM via host {@see VmPreg::pregFilter}; JIT/AOT via {@see JitPregFilter}.
 */
final class preg_filter extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_filter');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException(
                'preg_filter() expects 3 to 5 arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_filter() pattern', 0);
        $replacement = VmReflection::stringArg($frame->calledArgs[1], 'preg_filter() replacement', 1);
        $subjectVar = VmPreg::requireStringOrArraySubject(
            $frame->calledArgs[2],
            'preg_filter',
            2,
            'subject'
        );
        $limit = -1;
        $flags = 0;
        if ($argc >= 4) {
            $limitVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \LogicException(
                    'preg_filter() limit must be an integer in this compiler build'
                );
            }
            $limit = $limitVar->toInt();
        }
        if (5 === $argc) {
            $flagsVar = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException(
                    'preg_filter() flags must be an integer in this compiler build'
                );
            }
            $flags = $flagsVar->toInt();
        }

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $result = VmPreg::pregFilter($pattern, $replacement, $subjectVar->toString(), $limit, $flags);
        } elseif (Variable::TYPE_ARRAY === $subjectVar->type) {
            $host = [];
            foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
                if (Variable::TYPE_STRING !== $value->type) {
                    throw new \LogicException(
                        'preg_filter() array values must be strings in this compiler build'
                    );
                }
                $hostKey = Variable::TYPE_INTEGER === $key->type
                    ? $key->toInt()
                    : $key->toString();
                $host[$hostKey] = $value->toString();
            }
            $result = VmPreg::pregFilter($pattern, $replacement, $host, $limit, $flags);
        }

        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        if (\is_string($result)) {
            $frame->returnVar->string($result);

            return;
        }
        $ht = new HashTable();
        foreach ($result as $key => $line) {
            $keyVar = new Variable();
            if (\is_int($key)) {
                $keyVar->int($key);
            } else {
                $keyVar->string((string) $key);
            }
            $value = new Variable();
            $value->string((string) $line);
            array_map::appendKeyedCopy($ht, $keyVar, $value);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3 || $argc > 5) {
            throw new \LogicException(
                'preg_filter() expects 3 to 5 arguments in this compiler build'
            );
        }
        $limit = $argc >= 4
            ? self::lowerLimit($context, $args[3])
            : $context->getTypeFromString('int64')->constInt(-1, false);
        if (5 === $argc) {
            self::lowerFlags($context, $args[4]);
        }

        JitPregSubject::requireStringOrArray($context, $args[2], 'preg_filter', 2, 'subject');

        return JitPregFilter::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'preg_filter() pattern'),
            JitStringArg::lower($context, $args[1], 'preg_filter() replacement'),
            $args[2],
            $limit
        );
    }

    private static function lowerLimit(Context $context, JITVariable $arg): Value
    {
        $lit = self::compileTimeLimit($arg);
        if (null !== $lit) {
            return $context->constantFromInteger($lit, 'int64');
        }

        return JitLongArg::lower($context, $arg, 'preg_filter() argument #4 ($limit)');
    }

    /** @see VmPregNative::pregFilter() — flags accepted for arity parity; not applied yet */
    private static function lowerFlags(Context $context, JITVariable $arg): Value
    {
        $lit = self::compileTimeLimit($arg);
        if (null !== $lit) {
            return $context->constantFromInteger($lit, 'int64');
        }

        return JitLongArg::lower($context, $arg, 'preg_filter() argument #5 ($flags)');
    }

    private static function compileTimeLimit(JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type
            || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        if (null !== ($arg->compileTimeLong ?? null)) {
            return (int) $arg->compileTimeLong;
        }

        return null;
    }
}
