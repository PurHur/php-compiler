<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * version_compare() — PHP-standardized version strings (ext/standard/versioning.c parity, #3204).
 *
 * Z_PARAM_STR $version1 / $version2 — soft-null DEP+coerce on PROFILE=8.4 (#21556, reverts #20254 TypeError).
 * Optional $operator remains nullable (?string).
 * Excess/missing argc → Zend ArgumentCountError (#30593).
 */
final class version_compare extends Internal
{
    public function __construct()
    {
        parent::__construct('version_compare');
    }

    public function execute(Frame $frame): void
    {
        // php-src stub arity: 2..3 (#30593; ext/standard/versioning.c).
        $this->requireArgCountRange($frame, 'version_compare', 2, 3);
        $argc = \count($frame->calledArgs);
        // Z_PARAM_STR — soft-null DEP+coerce on PROFILE=8.4 (#21556, Zend 8.4.23 versioning.c).
        $ver1 = VmString::trimFamilyStringArgForFrame($frame, 0, 'version_compare', 0, 'version1');
        $ver2 = VmString::trimFamilyStringArgForFrame($frame, 1, 'version_compare', 1, 'version2');
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
        // Catchable ArgumentCountError under AOT try/catch (#30593 / peer #30537).
        if (!$this->requireArgCountRangeJit($context, $args, 'version_compare', 2, 3)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitInfo::version_compare(
            $context,
            $args[0],
            $args[1],
            $args[2] ?? null
        );
    }
}
