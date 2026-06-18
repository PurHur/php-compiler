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
            if (!CompilerVersion::supportsStrIncrement()
                && (str_contains($name, 'str_increment') || str_contains($name, 'str_decrement'))) {
                continue;
            }
            // 8.2-target reject gate; skipped when CompilerVersion 8.3+ enables typed trait constants (#5993).
            if (CompilerVersion::supportsTypedTraitConstants()
                && str_contains($name, 'trait_typed_const_reject')) {
                continue;
            }
            if (CompilerVersion::supportsNewInClassConstantExpr()
                && (
                    str_contains($name, 'new_in_class_constant_reject')
                    || str_contains($name, 'new_in_constant_expr')
                    || str_contains($name, 'class_const_new_rejected')
                    || str_contains($name, 'class_const_new_object')
                )) {
                continue;
            }
            if (str_contains(strtolower($case[0]), 'splobjectstorage')) {
                continue;
            }
            if (str_contains(strtolower($case[0]), 'spl_autoload_register_jit')) {
                continue;
            }
            if (str_contains($name, 'setcookie_jit') || str_contains($name, 'setrawcookie_jit')) {
                continue;
            }
            if (str_contains($name, 'dynamic_property_deprecation')) {
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