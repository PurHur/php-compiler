<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** Empty-string builtin ValueError wording must match Zend (#29275 / #29276 / #29291 / #29292 / #29422). */
final class EmptyStringArgValueErrorMessageTest extends TestCase
{
    public function test_shared_formatter_matches_zend(): void
    {
        self::assertSame('must not be empty', VmString::EMPTY_STRING_ARG_VALUE_ERROR_MUST_NOT);
        self::assertSame('cannot be empty', VmString::EMPTY_STRING_ARG_VALUE_ERROR_CANNOT);
        self::assertSame(
            'hash_hkdf(): Argument #2 ($key) cannot be empty',
            VmString::emptyStringArgValueErrorMessageCannot('hash_hkdf', 1, 'key')
        );
        self::assertSame(
            'checkdnsrr(): Argument #1 ($hostname) cannot be empty',
            VmString::emptyStringArgValueErrorMessageCannot('checkdnsrr', 0, 'hostname')
        );
        self::assertSame(
            'explode(): Argument #1 ($separator) must not be empty',
            VmString::emptyStringArgValueErrorMessage('explode', 0, 'separator')
        );
        self::assertSame(
            'substr_count(): Argument #2 ($needle) must not be empty',
            VmString::emptyStringArgValueErrorMessage('substr_count', 1, 'needle')
        );
        self::assertSame(
            'wordwrap(): Argument #3 ($break) must not be empty',
            VmString::emptyStringArgValueErrorMessage('wordwrap', 2, 'break')
        );
        self::assertSame(
            'str_pad(): Argument #3 ($pad_string) must not be empty',
            VmString::emptyStringArgValueErrorMessage('str_pad', 2, 'pad_string')
        );
        self::assertSame(
            'mb_str_pad(): Argument #3 ($pad_string) must not be empty',
            VmString::emptyStringArgValueErrorMessage('mb_str_pad', 2, 'pad_string')
        );
    }

    public function test_vm_explode_and_substr_count_empty_messages(): void
    {
        $bin = realpath(__DIR__.'/../../bin/vm.php');
        self::assertNotFalse($bin);
        foreach ([
            'issue29275_explode_empty_separator_message.php',
            'issue29276_substr_count_empty_needle_message.php',
            'issue29291_wordwrap_empty_break_message.php',
            'issue29292_str_pad_empty_pad_message.php',
            'issue29422_mb_str_pad_empty_pad_message.php',
        ] as $name) {
            $repro = realpath(__DIR__.'/../repro/'.$name);
            self::assertNotFalse($repro, $name);
            $cmd = [
                PHP_BINARY,
                '-d', 'memory_limit=512M',
                $bin,
                $repro,
            ];
            $env = array_merge($_ENV, $_SERVER, ['PHP_COMPILER_PROFILE' => '8.4']);
            foreach ($env as $k => $v) {
                if (!is_string($v)) {
                    unset($env[$k]);
                }
            }
            $proc = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 2),
                $env
            );
            self::assertIsResource($proc);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            self::assertSame(0, $code, $name."\n".$stderr.$stdout);
            self::assertStringContainsString('must not be empty', $stdout);
            self::assertStringContainsString("ok\n", $stdout);
        }
    }
}
