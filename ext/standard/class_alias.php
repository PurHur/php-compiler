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
 * class_alias() — register alternate class names (issue #3095).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_alias)
 * VM only: aliases are resolved at runtime via Context::registerClassAlias.
 */
final class class_alias extends Internal
{
    public function __construct()
    {
        parent::__construct('class_alias');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2 || \count($frame->calledArgs) > 3) {
            throw new \LogicException('class_alias() requires two or three arguments in this compiler build');
        }
        $ctx = VmReflection::requireContext($frame);
        $original = VmReflection::stringArg($frame->calledArgs[0], 'class_alias() original class');
        $alias = VmReflection::stringArg($frame->calledArgs[1], 'class_alias() alias');
        $autoload = true;
        if (3 === \count($frame->calledArgs)) {
            $autoloadArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $autoloadArg->type) {
                throw new \LogicException('class_alias() autoload must be a boolean in this compiler build');
            }
            $autoload = $autoloadArg->toBool();
        }
        $ok = $ctx->registerClassAlias($original, $alias, $autoload);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('class_alias() is not implemented for JIT in this compiler build');
    }
}
