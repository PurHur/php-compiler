<?php

/**
 * Repro #22493 — get_class_vars() must omit virtual hooked properties (ZEND_ACC_VIRTUAL).
 *
 * php-src: Zend/zend_builtin_functions.c — add_class_vars skips ZEND_ACC_VIRTUAL.
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_get_class_vars_omit_virtual_hooks.php
 */

class H {
    public string $a { get => 'x'; set {} }
    public $b = 2;
    public string $c { get => $this->c; set => $this->c = $value; }
}

// Zend 8.4: omit virtual $a only; keep plain $b and same-name backed $c (#22493 / #23881).
echo json_encode(get_class_vars(H::class)), "\n";
echo array_key_exists('a', get_class_vars(H::class)) ? "a-yes\n" : "a-no\n";
echo array_key_exists('b', get_class_vars(H::class)) ? "b-yes\n" : "b-no\n";
echo array_key_exists('c', get_class_vars(H::class)) ? "c-yes\n" : "c-no\n";
