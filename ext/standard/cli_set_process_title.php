<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** cli_set_process_title() — CLI worker title (php-src ext/standard/cli_ops.c; #5155). */
final class cli_set_process_title extends Internal
{
    public function __construct()
    {
        parent::__construct('cli_set_process_title');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'cli_set_process_title', 1);
        $title = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'cli_set_process_title',
            0,
            'title'
        );
        $ok = VmCli::setProcessTitle($title);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($ok): void {
            $ret->bool($ok);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'cli_set_process_title() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitCliProcessTitle::set($context, $args[0]);
    }
}
