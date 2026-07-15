<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * spl_autoload_extensions() — get/set default extensions for spl_autoload() (ext/spl/php_spl.c).
 */
final class spl_autoload_extensions extends Internal
{
    public function __construct()
    {
        parent::__construct('spl_autoload_extensions');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'spl_autoload_extensions() expects at most 1 argument, '.$argc.' given'
            );
        }
        if (1 === $argc) {
            $extVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $extVar->type) {
                VmSplAutoload::setFileExtensions(
                    VmString::coerceStringBuiltinArg(
                        $frame->calledArgs[0],
                        'spl_autoload_extensions',
                        1,
                        'file_extensions'
                    )
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmSplAutoload::fileExtensions());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSplAutoloadDefault::extensions($context, ...$args);
    }
}
