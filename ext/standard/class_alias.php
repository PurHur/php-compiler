<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * class_alias() — register alternate class names (issue #3095).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(class_alias)
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
        // php-src Zend/zend_builtin_functions.stub.php — string $class / string $alias.
        // Z_PARAM_STR: declare(strict_types=1) → TypeError on null; else soft-null DEP+coerce (#29816 / #29661).
        $original = VmString::trimFamilyStringArgForFrame($frame, 0, 'class_alias', 0, 'class');
        $alias = VmString::trimFamilyStringArgForFrame($frame, 1, 'class_alias', 1, 'alias');
        $autoload = true;
        if (3 === \count($frame->calledArgs)) {
            $autoloadArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $autoloadArg->type) {
                throw new \LogicException('class_alias() autoload must be a boolean in this compiler build');
            }
            $autoload = $autoloadArg->toBool();
        }
        $ok = $ctx->registerClassAlias($original, $alias, $autoload, $frame);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('class_alias() requires two or three arguments in this compiler build');
        }
        $autoloadArg = 3 === \count($args) ? $args[2] : null;
        // Under strict_types, force Z_PARAM_STR TypeError before literal fold (#29816).
        if (!$context->callerStrictTypes) {
            $originalLit = JitStringArg::compileTimeLiteral($args[0]);
            $aliasLit = JitStringArg::compileTimeLiteral($args[1]);
            if (null !== $originalLit && null !== $aliasLit
                && !(JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false))
                && !(JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))
            ) {
                return JitClassAlias::invokeLiteral($context, $originalLit, $aliasLit, $autoloadArg);
            }
        }

        $originalStr = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'class_alias', 0, 'class')
            : JitStringBuiltinArg::lower($context, $args[0], 'class_alias', 0, 'class', 'string', null, false);
        $aliasStr = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[1], 'class_alias', 1, 'alias')
            : JitStringBuiltinArg::lower($context, $args[1], 'class_alias', 1, 'alias', 'string', null, false);

        return JitClassAlias::invokeRuntime(
            $context,
            $originalStr,
            $aliasStr,
            $args[0],
            $args[1],
            $autoloadArg
        );
    }
}
