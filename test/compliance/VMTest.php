<?php

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Backend\VM\Runtime;

require_once __DIR__ . '/../BaseTest.php';

class VMTest extends BaseTest {
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (parent::providePHPTests() as $name => $case) {
            if (str_contains(strtolower($case[0]), 'splobjectstorage')) {
                continue;
            }
            if (str_contains(strtolower($case[0]), 'spl_autoload_register_jit')) {
                continue;
            }
            // Native preg stub error codes (JIT/AOT); VM uses host PCRE (issue #1181, #3110).
            if (str_contains(strtolower($case[0]), 'preg_last_error') && str_contains(strtolower($case[0]), 'jit')) {
                continue;
            }
            yield $name => $case;
        }
    }

    public function setUp(): void {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }

}