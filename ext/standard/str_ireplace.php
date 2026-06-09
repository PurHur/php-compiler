<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\RuntimeStrictness;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** str_ireplace() — case-insensitive str_replace for strings (VM + JIT/AOT; libc strcasestr in JIT). */
final class str_ireplace extends Internal
{
    public function __construct()
    {
        parent::__construct('str_ireplace');
    }

    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('str_ireplace() requires exactly three arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $search = self::coerceStringReplaceArg($frame->calledArgs[0], 'str_ireplace', 0, 'search');
        $replace = self::coerceStringReplaceArg($frame->calledArgs[1], 'str_ireplace', 1, 'replace');
        $subject = self::coerceStringReplaceArg($frame->calledArgs[2], 'str_ireplace', 2, 'subject');
        $frame->returnVar->string(VmString::strIreplace($search, $replace, $subject));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException('str_ireplace() requires exactly three arguments in this compiler build');
        }

        return JitStrIreplace::replace(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'str_ireplace', 0, 'search', 'array|string'),
            JitStringBuiltinArg::lower($context, $args[1], 'str_ireplace', 1, 'replace', 'array|string'),
            JitStringBuiltinArg::lower($context, $args[2], 'str_ireplace', 2, 'subject', 'array|string')
        );
    }

    /**
     * php-src Z_PARAM_STR_OR_ARR on str_ireplace() string path — enum cases TypeError (#5889).
     */
    private static function coerceStringReplaceArg(
        Variable $var,
        string $function,
        int $argIndex,
        string $paramName
    ): string {
        $var = $var->resolveIndirect();
        if (RuntimeStrictness::enforceStringBuiltinParityGuards() && EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type array|string, %s given',
                $function,
                $argIndex + 1,
                $paramName,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_STRING !== $var->type) {
            throw new \LogicException("{$function}() requires string arguments in this compiler build");
        }

        return $var->toString();
    }
}
