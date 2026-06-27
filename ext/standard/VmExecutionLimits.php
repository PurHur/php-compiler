<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ScriptExit;

/**
 * VM execution time limit + ignore_user_abort state (php-src main/php_globals.h, basic_functions.c; #3242).
 *
 * PHP-in-PHP: no runtime/*.c — opcode-loop polling only.
 */
final class VmExecutionLimits
{
    private const DEFAULT_MAX_SECONDS = 30;

    private float $deadline = 0.0;

    private int $activeLimitSeconds = self::DEFAULT_MAX_SECONDS;

    private int $ignoreUserAbort = 0;

    private int $opcodeCheckCounter = 0;

    /** Start counting from script entry (VM::run). */
    public function begin(): void
    {
        $this->resetTimer(self::DEFAULT_MAX_SECONDS);
    }

    /**
     * set_time_limit() — returns false when not in the main script (included file).
     */
    public function setTimeLimit(Context $ctx, int $seconds): bool
    {
        if ($ctx->scriptStack->depth() > 1) {
            return false;
        }
        $this->applyMaxExecutionTime($seconds);

        return true;
    }

    /** ini_set('max_execution_time') / internal sync — no include-depth guard (#12481). */
    public function applyMaxExecutionTime(int $seconds): void
    {
        $this->activeLimitSeconds = $seconds;
        $this->resetTimer($seconds);
        VmIni::syncMaxExecutionTime($seconds);
    }

    /** ignore_user_abort(?bool $setting) — returns previous int flag. */
    public function ignoreUserAbort(?bool $setting): int
    {
        $previous = $this->ignoreUserAbort;
        if (null !== $setting) {
            $this->ignoreUserAbort = $setting ? 1 : 0;
        }

        return $previous;
    }

    /** connection_aborted() — CLI phase 1 always 0 (#49 web wiring later). */
    public function connectionAborted(): int
    {
        return 0;
    }

    public function check(Context $ctx, Frame $frame): void
    {
        if (0.0 === $this->deadline) {
            return;
        }
        if ((++$this->opcodeCheckCounter & 0xFF) !== 0) {
            return;
        }
        if (microtime(true) < $this->deadline) {
            return;
        }
        self::throwTimeoutExceeded($ctx, $frame, $this->activeLimitSeconds);
    }

    private function resetTimer(int $seconds): void
    {
        if (0 === $seconds) {
            $this->deadline = 0.0;

            return;
        }
        $this->deadline = microtime(true) + (float) $seconds;
    }

    /**
     * @return never
     */
    private static function throwTimeoutExceeded(Context $ctx, Frame $frame, int $limitSeconds): void
    {
        $file = $frame->scriptPath;
        if ('' === $file) {
            $file = $ctx->scriptStack->current();
        }
        $line = max(1, $frame->pos);
        $limitLabel = 1 === $limitSeconds ? '1 second' : "{$limitSeconds} seconds";
        $message = "Maximum execution time of {$limitLabel} exceeded";
        if ('' !== $file) {
            $message .= " in {$file} on line {$line}";
        }
        $formatted = "PHP Fatal error:  {$message}\n";
        if ($ctx->errors->getDisplayErrors()) {
            fwrite(STDERR, $formatted);
        }
        throw new ScriptExit(255);
    }
}
