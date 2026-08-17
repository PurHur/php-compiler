<?php
class C {
    public array $a;
}
function t($label, $fn) {
    try {
        $fn();
        echo $label, "=ok\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
t('inc', function () {
    $o = new C;
    $o->a[0]++;
    var_export($o->a);
    echo "\n";
});
t('add', function () {
    $o = new C;
    $o->a[0] += 1;
    var_export($o->a);
    echo "\n";
});
t('preinc', function () {
    $o = new C;
    ++$o->a[0];
    var_export($o->a);
    echo "\n";
});
t('assign', function () {
    $o = new C;
    $o->a[0] = 1;
    var_export($o->a);
    echo "\n";
});
t('append', function () {
    $o = new C;
    $o->a[] = 2;
    var_export($o->a);
    echo "\n";
});
echo "after\n";
