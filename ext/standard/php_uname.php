<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** php_uname() — operating system identification (ext/standard/info.c parity, issue #3174). */
final class php_uname extends Internal
{
    public function __construct()
    {
        parent::__construct('php_uname');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \LogicException('php_uname() accepts at most one argument');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $mode = 'a';
        if (1 === $argc) {
            $mode = self::coerceModeArg($frame->calledArgs[0]);
        }
        $frame->returnVar->string(VmInfo::php_uname($mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) > 1) {
            throw new \LogicException('php_uname() accepts at most one argument');
        }
        if (!isset($args[0])) {
            return JitInfo::php_uname($context, null);
        }
        if ($context->isUserScriptAot()) {
            $lit = $args[0]->compileTimeString ?? JitStringArg::compileTimeLiteral($args[0]);
            if (null !== $lit) {
                return JitInfo::emitUserScriptStringLiteral($context, InfoJitHelper::php_uname($lit));
            }
        }
        $mode = JitStringBuiltinArg::lower(
            $context,
            $args[0],
            'php_uname',
            0,
            'mode',
            'string',
            null,
            self::forwardsProfile84()
        );

        return JitInfo::php_uname($context, $mode);
    }

    private static function forwardsProfile84(): bool
    {
        return version_compare(self::effectiveProfileVersion(), '8.4.0', '>=');
    }

    private static function effectiveProfileVersion(): string
    {
        $raw = VmEnv::getenv('PHP_COMPILER_PROFILE');
        if (false === $raw || '' === $raw) {
            return CompilerVersion::languageProfileVersion();
        }
        $raw = trim($raw);
        if (preg_match('/^\d+\.\d+$/', $raw)) {
            return $raw.'.0';
        }
        if (preg_match('/^\d+\.\d+\.\d+/', $raw, $m)) {
            return $m[0];
        }

        return CompilerVersion::languageProfileVersion();
    }

    private static function coerceModeArg(\PHPCompiler\VM\Variable $arg): string
    {
        if (self::forwardsProfile84()) {
            return VmString::coerceTypedStringBuiltinArg($arg, 'php_uname', 0, 'mode');
        }

        return VmString::coerceStringBuiltinArg(
            $arg,
            'php_uname',
            0,
            'mode',
            'string',
            false
        );
    }
}
