<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * finfo_set_flags() — update sniff flags (php-src ext/fileinfo/fileinfo.c; #3366, #34688).
 *
 * Thin AOT: accept finfo + flags arity, return true. MIME_TYPE AOT sniff ignores flags
 * (#27196 / FinfoConstruct).
 *
 * @see https://github.com/php/php-src/blob/master/ext/fileinfo/fileinfo.c PHP_FUNCTION(finfo_set_flags)
 */
final class finfo_set_flags extends Internal
{
    public function __construct()
    {
        parent::__construct('finfo_set_flags');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_set_flags() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        $finfo = VmFinfo::requireFinfoArg($frame->calledArgs[0], 'finfo_set_flags', 0);
        $flags = VmFinfo::coerceFlagsArg($frame, 1, 'finfo_set_flags', 2, 'flags');
        $ok = VmFinfo::setFlags($finfo, $flags);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return self::lowerSetFlags($context, false, ...$args);
    }

    /**
     * Shared by procedural finfo_set_flags() and finfo::set_flags().
     *
     * @param bool $method true when $args[0] is $this (OO)
     */
    public static function lowerSetFlags(Context $context, bool $method, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($method) {
            if (2 !== $argc) {
                throw new \ArgumentCountError(\sprintf(
                    'finfo::set_flags() expects exactly 1 argument, %d given',
                    \max(0, $argc - 1)
                ));
            }
        } elseif (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_set_flags() expects exactly 2 arguments, %d given',
                $argc
            ));
        }
        ReflectionSetup::loadObjectFromArg($context, $args[0]);
        // Flags arg kept for arity / future FILEINFO_NONE path; MIME_TYPE AOT ignores it.

        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(1, false));

        // Standalone AOT needs boxed bool (Internal::boxStandaloneBoolJitResult); OO method
        // call sites go through the same assign path — always return the value pointer.
        return JitValueBox::pointer($context, $slot);
    }
}
