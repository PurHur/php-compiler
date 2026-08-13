<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** clearstatcache() — VM via VmStatCache; JIT/AOT via StatCacheJitHelper (#9110, #9244). */
final class clearstatcache_ extends Internal
{
    public function __construct()
    {
        parent::__construct('clearstatcache');
    }

    public function execute(Frame $frame): void
    {
        // php-src filestat.c / basic_functions.stub.php — at most 2 (#30554).
        $this->requireAtMostArgCount($frame, 'clearstatcache', 2);
        $hasClearRealpath = isset($frame->calledArgs[0]);
        $hasFilename = isset($frame->calledArgs[1]);
        if (!$hasClearRealpath && !$hasFilename) {
            VmStatCache::clear();
        } elseif ($hasFilename) {
            $clearRealpath = $hasClearRealpath
                ? VmMath::parseBoolBuiltinArg(
                    $frame->calledArgs[0],
                    'clearstatcache',
                    0,
                    'clear_realpath_cache'
                )
                : false;
            $filename = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'clearstatcache',
                1,
                'filename'
            );
            VmStatCache::clear($clearRealpath, '' !== $filename ? $filename : null);
        } else {
            $clearRealpath = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[0],
                'clearstatcache',
                0,
                'clear_realpath_cache'
            );
            VmStatCache::clear($clearRealpath);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->null();
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireAtMostJitArgCount($context, $args, 'clearstatcache', 2)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitClearstatcache::invoke($context, self::effectiveArgCount($args), ...$args);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function effectiveArgCount(array $args): int
    {
        $hasFilename = isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1]);
        if ($hasFilename) {
            return 2;
        }
        $hasClearRealpath = isset($args[0]) && !NamedOptionalCallArgs::isOmittedOptional($args[0]);

        return $hasClearRealpath ? 1 : 0;
    }
}
