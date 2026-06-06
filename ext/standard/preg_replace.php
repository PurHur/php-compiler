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
 * preg_replace() — VM via VmPreg; JIT/AOT via __compiler_preg_replace (issue #1176).
 * Optional $limit (4th arg): VM (#3605); JIT/AOT deferred until native runtime supports it.
 * Array $subject: VM + JIT (#4055, ext/pcre/php_pcre.c php_pcre_replace).
 */
final class preg_replace extends Internal
{
    public function __construct()
    {
        parent::__construct('preg_replace');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException(
                'preg_replace() expects 3 to 4 arguments in this compiler build'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pattern = VmReflection::stringArg($frame->calledArgs[0], 'preg_replace() pattern', 0);
        $replacement = VmReflection::stringArg($frame->calledArgs[1], 'preg_replace() replacement', 1);
        $subjectVar = $frame->calledArgs[2]->resolveIndirect();
        $limit = -1;
        if (4 === $argc) {
            $limitVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $limitVar->type) {
                throw new \LogicException(
                    'preg_replace() limit must be an integer in this compiler build'
                );
            }
            $limit = $limitVar->toInt();
        }

        if (Variable::TYPE_STRING === $subjectVar->type) {
            $result = VmPreg::pregReplace($pattern, $replacement, $subjectVar->toString(), $limit);
        } elseif (Variable::TYPE_ARRAY === $subjectVar->type) {
            $host = [];
            foreach ($subjectVar->toArray()->iterateKeyed(true) as [$key, $value]) {
                if (Variable::TYPE_STRING !== $value->type) {
                    throw new \LogicException(
                        'preg_replace() array values must be strings in this compiler build'
                    );
                }
                $hostKey = Variable::TYPE_INTEGER === $key->type
                    ? $key->toInt()
                    : $key->toString();
                $host[$hostKey] = $value->toString();
            }
            $result = VmPreg::pregReplace($pattern, $replacement, $host, $limit);
        } else {
            throw new \LogicException(
                'preg_replace() subject must be a string or array in this compiler build'
            );
        }

        if (false === $result) {
            $frame->returnVar->bool(false);

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
        if ($argc < 3 || $argc > 4) {
            throw new \LogicException(
                'preg_replace() expects 3 to 4 arguments in this compiler build'
            );
        }
        if ($argc >= 4) {
            throw new \LogicException(
                'preg_replace() limit is not supported in JIT/AOT in this compiler build (issue #3605)'
            );
        }

        $pattern = JitStringArg::lower($context, $args[0], 'preg_replace() pattern');
        $replacement = JitStringArg::lower($context, $args[1], 'preg_replace() replacement');
        if (JITVariable::TYPE_STRING === $args[2]->type) {
            return JitPregReplace::invokeString(
                $context,
                $pattern,
                $replacement,
                JitStringArg::lower($context, $args[2], 'preg_replace() subject')
            );
        }

        return JitPregReplace::invokeArray($context, $pattern, $replacement, $args[2]);
    }
}
