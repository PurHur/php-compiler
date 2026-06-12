<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** cli_get_process_title() — read CLI worker title (php-src ext/standard/cli_ops.c; #5155). */
final class cli_get_process_title extends Internal
{
    public function __construct()
    {
        parent::__construct('cli_get_process_title');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, 'cli_get_process_title', 0);
        $title = VmCli::getProcessTitle();
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($title): void {
            $ret->string($title);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('cli_get_process_title() is not implemented for JIT in this compiler build');
    }
}
