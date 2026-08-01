<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * file() — read path into array of lines (ext/standard/file.c; issue #3765, #24454).
 *
 * php-src: `function file(string $filename, int $flags = 0, ?resource $context = null): array|false`
 */
final class file_ extends Internal
{
    public function __construct()
    {
        parent::__construct('file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'file() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'file() expects at most 3 arguments, '.$argc.' given'
            );
        }
        $path = VmStreamPath::coerceNonEmptyPathArgForFrame($frame, 0, 'file', 'filename');
        $flags = 0;
        if (isset($frame->calledArgs[1])) {
            $flags = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'file', 1, 'flags');
        }
        if (isset($frame->calledArgs[2])) {
            $contextVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $contextVar->type) {
                VmStreamContext::requireRepresentation($contextVar, 'file', 3);
            }
        }
        if (null === $frame->returnVar) {
            return;
        }

        $lines = VmFs::file($path, $flags);
        if (false === $lines) {
            // php-src ext/standard/file.c — open failure → E_WARNING + false (#26695)
            VmStreamOpenFailure::warnFailedToOpen($frame, 'file', $path);
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($lines as $line) {
            $value = new Variable();
            $value->string($line);
            $ht->append($value);
        }
        $frame->returnVar->array($ht);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'file() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(
                'file() expects at most 3 arguments, '.$argc.' given'
            );
        }
        $path = JitStreamPath::lowerNonEmptyPath($context, $args[0], 'file');
        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        if (isset($args[1])) {
            $flags = JitLongArg::lower($context, $args[1], 'file() flags');
        }
        if (isset($args[2])) {
            JitStreamContextOptionalArg::validate($context, $args[2], 'file', 3);
        }

        return JitFile::invoke($context, $path, $flags);
    }
}
