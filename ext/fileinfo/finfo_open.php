<?php

declare(strict_types=1);

namespace PHPCompiler\ext\fileinfo;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\ext\standard\VmString;
use PHPLLVM\Value;

/**
 * finfo_open() — create finfo handle (php-src ext/fileinfo/fileinfo.c; #3366, #34688).
 *
 * JIT/AOT: allocate + markConstructed (peer {@see \PHPCompiler\JIT\Call\FinfoConstruct} / #27196).
 * Flags/magic_database are accepted for signature parity; MIME sniff via finfo_file ignores them
 * the same way the OO thin path does.
 *
 * @see https://github.com/php/php-src/blob/master/ext/fileinfo/fileinfo.c PHP_FUNCTION(finfo_open)
 */
final class finfo_open extends Internal
{
    public function __construct()
    {
        parent::__construct('finfo_open');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_open() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $flags = VmFinfo::coerceFlagsArg($frame, 0, 'finfo_open', 1, 'flags');
        if (isset($frame->calledArgs[1])) {
            VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'finfo_open',
                1,
                'magic_database'
            );
        }
        $var = VmFinfo::open($flags, $frame->vmContext);
        $frame->returnVar->object($var->toObject());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'finfo_open() expects at most 2 arguments, %d given',
                $argc
            ));
        }
        // Thin AOT (#34688 / #27196): allocate finfo + markConstructed — same shape as FinfoConstruct.
        $classId = $context->type->object->lookup('finfo');
        $obj = $context->type->object->allocate($classId);
        ReflectionSetup::markConstructed($context, $obj);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $obj
        );

        return $ptr;
    }
}
