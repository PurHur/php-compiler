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

/** parse_url() for http(s) URLs and path/query routing (subset of PHP; JIT/AOT via ParseUrlRuntime). */
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
            $component = VmParseUrl::resolveComponentArg($frame->calledArgs[1]);
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
        if (null === $result) {
            $frame->returnVar->null();

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

        return JitParseUrl::parseUrl($context, $args[0], $component);
    }
}
