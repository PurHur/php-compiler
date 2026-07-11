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
 * spl_autoload() — default include_path class loader (ext/spl/php_spl.c, #4256).
 */
final class spl_autoload extends Internal
{
    public function __construct()
    {
        parent::__construct('spl_autoload');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(
                'spl_autoload() expects 1 or 2 arguments, '.$argc.' given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $className = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'spl_autoload',
            0,
            'class_name'
        );
        $fileExts = null;
        if (2 === $argc) {
            $extVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $extVar->type) {
                $fileExts = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'spl_autoload',
                    2,
                    'file_extensions'
                );
            }
        }
        VmSplAutoload::defaultAutoload($ctx, $className, $fileExts);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitSplAutoloadDefault::autoload($context, ...$args);
    }
}
