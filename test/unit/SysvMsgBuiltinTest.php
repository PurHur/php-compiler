<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** VM compliance for ext/sysvmsg msg_* APIs (#3666). */
final class SysvMsgBuiltinTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/vm.php');
    }

    public static function providePHPTests(): \Generator
    {
        if (!\function_exists('msg_get_queue')) {
            return;
        }

        $path = __DIR__.'/../compliance/cases/sysvmsg/msg_send_receive.phpt';
        yield 'msg_send_receive.phpt' => self::parsePHPT($path, 'msg_send_receive.phpt');

        $typesPath = __DIR__.'/../compliance/cases/sysvmsg/msg_reflection_types_28452.phpt';
        yield 'msg_reflection_types_28452.phpt' => self::parsePHPT($typesPath, 'msg_reflection_types_28452.phpt');
    }
}
