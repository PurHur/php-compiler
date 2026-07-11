<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlError;
use PHPCompiler\ext\intl\intl_get_error_code;
use PHPCompiler\ext\intl\intl_get_error_message;
use PHPCompiler\ext\intl\intl_is_failure;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** intl_get_error_* / intl_is_failure VM builtins (#5156). */
final class IntlErrorFunctionsTest extends TestCase
{
    protected function setUp(): void
    {
        IntlError::clear();
    }

    public function test_idle_error_state(): void
    {
        self::assertSame(0, $this->runGetErrorCode());
        self::assertSame('', $this->runGetErrorMessage());
        self::assertFalse($this->runIsFailure(0));
    }

    public function test_is_failure_negative_code(): void
    {
        self::assertTrue($this->runIsFailure(-1));
    }

    public function test_is_failure_using_fallback_warning_not_failure(): void
    {
        self::assertFalse($this->runIsFailure(IntlError::U_USING_FALLBACK_WARNING));
    }

    public function test_set_error_round_trip(): void
    {
        IntlError::set(5, 'test message');
        self::assertSame(5, $this->runGetErrorCode());
        self::assertSame('test message', $this->runGetErrorMessage());
        self::assertTrue($this->runIsFailure(5));
    }

    private function runGetErrorCode(): int
    {
        return $this->runVoidBuiltin(new intl_get_error_code());
    }

    private function runGetErrorMessage(): string
    {
        return $this->runVoidBuiltin(new intl_get_error_message());
    }

    private function runIsFailure(int $code): bool
    {
        return $this->runVoidBuiltin(new intl_is_failure(), $code);
    }

    /**
     * @return mixed
     */
    private function runVoidBuiltin(intl_get_error_code|intl_get_error_message|intl_is_failure $fn, ?int $arg = null)
    {
        $runtime = new Runtime();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs = [];
        if (null !== $arg) {
            $var = new VMVariable();
            $var->int($arg);
            $frame->calledArgs = [$var];
        }
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        if (Variable::TYPE_BOOLEAN === $frame->returnVar->type) {
            return $frame->returnVar->toBool();
        }
        if (Variable::TYPE_INTEGER === $frame->returnVar->type) {
            return $frame->returnVar->toInt();
        }

        return $frame->returnVar->toString();
    }
}
