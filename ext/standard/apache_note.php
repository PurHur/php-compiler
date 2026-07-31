<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * apache_note() — Apache request note table (#6276).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (#26120).
 * php-src: ext/standard/head.c — PHP_FUNCTION(apache_note)
 */
final class apache_note extends Internal
{
    private const HELPER_PATH = '/ext/standard/ApacheNoteJitHelper.php';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        ApacheNoteJitHelper::class.'::noteUnavailable',
        ApacheNoteJitHelper::class.'::versionUnavailable',
    ];

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
        self::ensureHelperCompiled($context);

        return $context->builder->call(
            JitVmHelperLink::lookupCompiled($context, $helper, '#26120'),
        );
    }

    private static function ensureHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#26120'
        );
    }
}
