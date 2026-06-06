<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** parse_url() for http(s) URLs and path/query routing (subset of PHP; JIT/AOT via ParseUrlJit). */
final class parse_url extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('parse_url() requires one or two arguments in this compiler build');
        }
        $url = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'parse_url', 0, 'url');
        $component = -1;
        if (2 === $argc) {
            $compVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $compVar->type) {
                throw new \LogicException('parse_url() component must be an integer in this compiler build');
            }
            $component = $compVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }

        $result = VmString::parseUrl($url, $component);
        if (\is_array($result)) {
            $ht = new HashTable();
            foreach ($result as $key => $value) {
                $slot = new Variable();
                if (\is_int($value)) {
                    $slot->int($value);
                } else {
                    $slot->string((string) $value);
                }
                $ht->add((string) $key, $slot);
            }
            $frame->returnVar->array($ht);

            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        $frame->returnVar->string((string) $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('parse_url() requires one or two arguments in this compiler build');
        }
        $component = 2 === $argc ? $args[1] : null;
        if (null !== $component
            && JITVariable::TYPE_NATIVE_LONG !== $component->type
            && JITVariable::TYPE_VALUE !== $component->type) {
            throw new \LogicException('parse_url() component must be an integer in this compiler build');
        }

        return JitParseUrl::parseUrl($context, $args[0], $component);
    }
}
