<?php

class C {
    private string $backing = 'x';
    public string $title {
        get => 'hook:' . $this->backing;
    }
}

// Virtual — omitted from get_class_vars (#22493 / ZEND_ACC_VIRTUAL).
var_export(array_key_exists('title', get_class_vars(C::class)));
echo "\n";
print_r(get_class_vars(C::class));

$o = new C();
var_export(get_class_vars(C::class) === get_object_vars($o));
echo "\n";

class G {
    private string $x = 'g_only';
    public string $y { get => $this->x; }
}
$g = new G();
$vars = get_class_vars(G::class);
echo "gcv_key:", array_key_exists('y', $vars) ? 'yes' : 'no', "\n";
echo "gcv_val:", var_export($vars['y'] ?? 'MISSING', true), "\n";
echo "gov:", var_export(get_object_vars($g)['y'] ?? 'MISSING', true), "\n";
