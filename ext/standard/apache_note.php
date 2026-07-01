<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT;
use PHPLLVM\Value;

/**
 * apache_note() — Apache request note table (#6276).
 *
 * php-src: ext/standard/head.c — PHP_FUNCTION(apache_note)
 */
final class apache_note extends Internal
{
    public function __construct()
    {
        parent::__construct('apache_note');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'apache_note() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $noteName = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'apache_note', 0, 'note_name');
        $noteValue = null;
        if (2 === $argc) {
            $noteValue = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'apache_note', 1, 'note_value');
        }

        $result = VmApache::note($frame, $noteName, $noteValue);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'apache_note() expects 1 or 2 arguments, %d given',
                $argc
            ));
        }
        if (!VmApache::isApacheSapi()) {
            return self::emitUnavailableJit($context, ApacheNoteJitHelper::class.'::noteUnavailable');
        }

        throw new \LogicException('apache_note() Apache SAPI JIT lowering is deferred (#6276)');
    }

    public static function emitUnavailableJit(Context $context, string $helper): Value
    {
        self::ensureHelperCompiled($context, $helper);

        return $context->builder->call(
            $context->functions[\strtolower($helper)],
        );
    }

    private static function ensureHelperCompiled(Context $context, string $logical): void
    {
        $lc = \strtolower($logical);
        if (isset($context->functions[$lc])) {
            return;
        }
        $runtime = $context->runtime;
        $path = \dirname(__DIR__).'/ApacheNoteJitHelper.php';
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ApacheNoteJitHelper.php');
            if (null === $block) {
                throw new \LogicException('ApacheNoteJitHelper.php parseAndCompile failed (#6276)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        if (!isset($context->functions[$lc])) {
            throw new \LogicException($logical.' was not compiled for JIT (#6276)');
        }
    }
}
