<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\error_log;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM/JIT registration for error_log() (#3380). */
final class ErrorLogBuiltinTest extends TestCase
{
    public function test_function_registered(): void
    {
        $runtime = new Runtime();
        self::assertTrue(VmReflection::functionExists($runtime->vmContext, 'error_log'));
    }

    public function test_type_zero_returns_true(): void
    {
        $runtime = new Runtime();
        $builtin = new error_log();
        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $message = new VMVariable();
        $message->string('unit test');
        $frame->calledArgs[] = $message;
        $builtin->execute($frame);
        self::assertTrue($frame->returnVar->resolveIndirect()->toBool());
    }

    public function test_type_three_appends_to_file(): void
    {
        $log = sys_get_temp_dir().'/phpc-error-log-unit-'.getmypid().'.log';
        @unlink($log);

        $runtime = new Runtime();
        $builtin = new error_log();
        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $message = new VMVariable();
        $message->string('file payload');
        $frame->calledArgs[] = $message;
        $type = new VMVariable();
        $type->int(3);
        $frame->calledArgs[] = $type;
        $dest = new VMVariable();
        $dest->string($log);
        $frame->calledArgs[] = $dest;
        $builtin->execute($frame);
        self::assertTrue($frame->returnVar->resolveIndirect()->toBool());
        self::assertSame('file payload', file_get_contents($log));
        @unlink($log);
    }

    public function test_type_two_throws_value_error(): void
    {
        $runtime = new Runtime();
        $builtin = new error_log();
        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $message = new VMVariable();
        $message->string('x');
        $frame->calledArgs[] = $message;
        $type = new VMVariable();
        $type->int(2);
        $frame->calledArgs[] = $type;

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('TCP/IP option is not available for error logging');
        $builtin->execute($frame);
    }

    public function test_type_three_empty_path_throws_value_error(): void
    {
        $runtime = new Runtime();
        $builtin = new error_log();
        $frame = $builtin->getFrame($runtime->vmContext);
        $frame->returnVar = new VMVariable();
        $message = new VMVariable();
        $message->string('x');
        $frame->calledArgs[] = $message;
        $type = new VMVariable();
        $type->int(3);
        $frame->calledArgs[] = $type;
        $dest = new VMVariable();
        $dest->string('');
        $frame->calledArgs[] = $dest;

        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Path must not be empty');
        $builtin->execute($frame);
    }

    /** @see https://github.com/PurHur/php-compiler/issues/21446 */
    public function test_null_message_coerces_on_forward84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $runtime = new Runtime();
            $builtin = new error_log();
            $frame = $builtin->getFrame($runtime->vmContext);
            $frame->returnVar = new VMVariable();
            $message = new VMVariable();
            $message->null();
            $frame->calledArgs[] = $message;
            $builtin->execute($frame);
            self::assertTrue($frame->returnVar->resolveIndirect()->toBool());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }

    /** Reference 8.2 profile still coerces null message like Zend 8.2 (#20253). */
    public function test_null_message_coerces_on_reference_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.2';
        try {
            $runtime = new Runtime();
            $builtin = new error_log();
            $frame = $builtin->getFrame($runtime->vmContext);
            $frame->returnVar = new VMVariable();
            $message = new VMVariable();
            $message->null();
            $frame->calledArgs[] = $message;
            $builtin->execute($frame);
            self::assertTrue($frame->returnVar->resolveIndirect()->toBool());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
