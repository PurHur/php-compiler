--TEST--
Language: anonymous class get_class()/static::class include source path (#6281, Zend zend_compile.c)
--FILE--
<?php
$o = new class {
    public function name(): string { return static::class; }
};
$gc = get_class($o);
$sc = $o->name();
if ($gc !== $sc) {
    echo "mismatch:get=", $gc, ":static=", $sc, "\n";
    exit(1);
}
if (!preg_match('/^class@anonymous\x00.+:2\$0$/', $gc)) {
    echo 'name_fail:', $gc, "\n";
    exit(1);
}
echo "name_ok\n";
--EXPECT--
name_ok
