--TEST--
Rvalue reads of hooked properties invoke get hook (issue #6473, zend_property_hooks.c)
--FILE--
<?php
class C {
    private string $x = 'via_hook';
    public string $y {
        get => $this->x;
        set => $this->x = $value;
    }
}
$c = new C();
[$a] = [$c->y];
echo "list:", $a, "\n";
echo "arg_len:", strlen($c->y), "\n";

class G {
    private string $x = 'g_only';
    public string $y { get => $this->x; }
}
$g = new G();
[$b] = [$g->y];
echo "get_only:", $b, "\n";
$vars = get_class_vars(G::class);
echo "gcv_key:", array_key_exists('y', $vars) ? 'yes' : 'no', "\n";
echo "gov:", get_object_vars($g)['y'] ?? 'MISSING', "\n";
--EXPECT--
list:via_hook
arg_len:8
get_only:g_only
gcv_key:no
gov:g_only
