--TEST--
get_class_vars() / get_object_vars() on property hooks (#6453, #5203, ext/standard/class.c)
--FILE--
<?php
class C {
    private string $backing = 'x';
    public string $title {
        get => 'hook:' . $this->backing;
    }
}

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
--EXPECT--
true
Array
(
    [title] => 
)
false
'hook:x'
gcv_key:yes
gov:g_only
