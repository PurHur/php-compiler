<?php
/** Repro for #29776 — filter_input/filter_has_var/filter_input_array null under strict_types. */
declare(strict_types=1);

$expected = [
    'filter_input' => 'filter_input(): Argument #2 ($var_name) must be of type string, null given',
    'filter_has_var' => 'filter_has_var(): Argument #2 ($var_name) must be of type string, null given',
    'filter_input_array' => 'filter_input_array(): Argument #2 ($options) must be of type array|int, null given',
];

foreach (['filter_input', 'filter_has_var', 'filter_input_array'] as $name) {
    try {
        if ('filter_input' === $name) {
            filter_input(INPUT_GET, null);
        } elseif ('filter_has_var' === $name) {
            filter_has_var(INPUT_GET, null);
        } else {
            filter_input_array(INPUT_GET, null);
        }
        fwrite(STDERR, "fail:$name:no_throw\n");
        exit(1);
    } catch (TypeError $e) {
        if ($expected[$name] !== $e->getMessage()) {
            fwrite(STDERR, "fail:$name:msg:".$e->getMessage()."\n");
            exit(1);
        }
        echo "$name: ok\n";
    }
}
