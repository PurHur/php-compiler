<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_filter() pattern');
        $replacement = VmReflection::stringArg($frame->calledArgs[1], 'preg_filter() replacement');
        $subjectVar = $frame->calledArgs[2]->resolveIndirect();
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
        } else {
            throw new \LogicException(
                'preg_filter() subject must be a string or array in this compiler build'
            );
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
        if ($argc >= 4) {
            throw new \LogicException(
                'preg_filter() limit is not supported in JIT/AOT in this compiler build'
            );
        }
        if (5 === $argc) {
            throw new \LogicException(
                'preg_filter() flags are not supported in JIT/AOT in this compiler build'
            );
        }

        return JitPregFilter::invoke(
            $context,
            JitStringArg::lower($context, $args[0], 'preg_filter() pattern'),
            JitStringArg::lower($context, $args[1], 'preg_filter() replacement'),
            $args[2]
        );
    }
}
