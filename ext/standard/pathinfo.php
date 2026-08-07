<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** pathinfo() for file paths (subset of PHP; JIT/AOT via JitPathinfo + PathinfoJitHelper #15322). */
final class pathinfo extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/file.stub.php — ArgumentCountError (#28286).
        $this->requireArgCountRange($frame, 'pathinfo', 1, 2);
        $argc = \count($frame->calledArgs);
        $path = VmFilestatArg::pathComponentFilenameArgForFrame($frame, 0, 'pathinfo', 'path');
        $flags = 15;
        if (2 === $argc) {
            $flags = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'pathinfo', 2, 'flags');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $result = VmString::pathinfo($path, $flags);
        if (\is_array($result)) {
            $ht = new HashTable();
            foreach ($result as $key => $value) {
                $slot = new Variable();
                $slot->string((string) $value);
                $ht->add((string) $key, $slot);
            }
            $frame->returnVar->array($ht);

            return;
        }
        $frame->returnVar->string((string) $result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer basename #28286.
        if ($argc < 1 || $argc > 2) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('pathinfo() expects at least 1 argument, %d given', $argc)
                    : \sprintf('pathinfo() expects at most 2 arguments, %d given', $argc)
            );

            return $unreachable;
        }
        $flags = 2 === $argc ? $args[1] : null;

        return JitPathinfo::invoke($context, $args[0], $flags);
    }
}
