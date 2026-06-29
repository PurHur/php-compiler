--TEST--
stdlib var_export() object private props JIT (#3594)
--FILE--
<?php
class D {
    private int $secret = 99;
    protected int $prot = 1;
    public int $pub = 2;
}
var_export(new D());
echo "\n";
--EXPECT--
\D::__set_state(array (
  'secret' => 99,
  'prot' => 1,
  'pub' => 2,
))
