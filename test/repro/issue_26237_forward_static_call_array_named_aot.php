<?php
/**
 * Issue #26237 AOT probe — named callback:/args: (string Class::method form).
 * php-src: ext/standard/basic_functions.stub.php
 *
 * Array callables under AOT for this builtin remain a separate gap (#20251);
 * string form exercises named-arg lowering end-to-end.
 */
class A
{
    public static function f()
    {
        return 42;
    }
}

var_export(forward_static_call_array(callback: 'A::f', args: []));
echo "\n";
?>
