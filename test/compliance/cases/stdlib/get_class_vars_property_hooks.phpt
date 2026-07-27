--TEST--
get_class_vars() / get_object_vars() on property hooks (#6453, #5203, #22493, Zend/zend_builtin_functions.c)
--FILE--
<?php
class C {
    private string $backing = 'x';
    public string $title {
        get => 'hook:' . $this->backing;
    }
}

// Virtual hooked props are omitted from get_class_vars (ZEND_ACC_VIRTUAL).
var_export(array_key_exists('title', get_class_vars(C::class)));
echo "\n";
print_r(get_class_vars(C::class));

$o = new C();
var_export(get_class_vars(C::class) === get_object_vars($o));
echo "\n";
$gov = get_object_vars($o);
$titleVal = $gov['title'];
var_export($titleVal);
echo "\n";

class G {
    private string $x = 'g_only';
    public string $y { get => $this->x; }
}
$g = new G();
$vars = get_class_vars(G::class);
echo "gcv_key:", array_key_exists('y', $vars) ? 'yes' : 'no', "\n";
$og = get_object_vars($g);
$yVal = $og['y'];
echo "gov:", $yVal, "\n";

// #22493 / #23881: omit true virtual only; same-name backed $c stays (Zend get_class_vars).
class H {
    public string $a { get => 'x'; set {} }
    public $b = 2;
    public string $c { get => $this->c; set => $this->c = $value; }
}
echo json_encode(get_class_vars(H::class)), "\n";
echo array_key_exists('a', get_class_vars(H::class)) ? "a-yes\n" : "a-no\n";
echo array_key_exists('c', get_class_vars(H::class)) ? "c-yes\n" : "c-no\n";
--EXPECT--
false
Array
(
)
false
'hook:x'
gcv_key:no
gov:g_only
{"b":2,"c":null}
a-no
c-yes
