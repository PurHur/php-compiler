<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** version_compare() — PHP-standardized version strings (ext/standard/versioning.c parity, #3204). */
final class version_compare extends Internal
{
    public function __construct()
    {
        parent::__construct('version_compare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('version_compare() expects 2 or 3 arguments');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'version_compare', 'version1', 0, $frame);
        InternalStrictArg::rejectNullString($frame->calledArgs[1], 'version_compare', 'version2', 1, $frame);
        $ver1 = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'version_compare', 0, 'version1');
        $ver2 = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'version_compare', 1, 'version2');
        $operator = null;
        if (3 === $argc) {
            $opVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $opVar->type) {
                if (Variable::TYPE_STRING !== $opVar->type) {
                    throw new \LogicException(
                        'version_compare() operator must be a string or null in this compiler build'
                    );
                }
                $operator = $opVar->toString();
            }
        }
        $result = VmInfo::version_compare($ver1, $ver2, $operator);
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($result): void {
            if (\is_bool($result)) {
                $ret->bool($result);
            } else {
                $ret->int($result);
            }
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('version_compare() expects 2 or 3 arguments');
        }

        return JitInfo::version_compare(
            $context,
            $args[0],
            $args[1],
            $args[2] ?? null
        );
    }
}
