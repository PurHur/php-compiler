<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** parse_url() for http(s) URLs and path/query routing (subset of PHP; JIT/AOT via ParseUrlRuntime). */
final class parse_url extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/basic_functions.stub.php — ArgumentCountError (#28691).
        $this->requireArgCountRange($frame, 'parse_url', 1, 2);
        $argc = \count($frame->calledArgs);
        // Soft-null — coerce+deprecate on forward profile (#21188, ext/standard/url.c)
        $url = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'parse_url',
            0,
            'url'
        );
        $component = -1;
        if (2 === $argc) {
            // Soft-null component → DEP + 0 (PHP_URL_SCHEME); ParseUrl enum (#7260, #24942).
            $component = VmParseUrl::resolveComponentArgForFrame($frame, 1);
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
        // Catchable ArgumentCountError (AOT) — peer #28228 / #28691.
        if (!$this->requireArgCountRangeJit($context, $args, 'parse_url', 1, 2)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        $argc = \count($args);
        $component = 2 === $argc ? $args[1] : null;

        return JitParseUrl::parseUrl($context, $args[0], $component);
    }
}
