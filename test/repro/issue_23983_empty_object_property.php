<?php
/**
 * Issue #23983: empty() on object/static properties uses value truthiness (zend_is_true),
 * not isset-alone; magic __isset then __get when overloaded.
 */
class C
{
    public $zero = 0;
    public $empty = '';
    public $zerostr = '0';
    public $false = false;
    public $arr = [];
    public $fzero = 0.0;
    public $null = null;
    public $one = 1;
}

$o = new C();
foreach (['zero', 'empty', 'zerostr', 'false', 'arr', 'fzero', 'null', 'one'] as $p) {
    echo $p, ':', empty($o->$p) ? '1' : '0', "\n";
}

class S
{
    public static $z = 0;
    public static $one = 1;
}
echo 'static0:', empty(S::$z) ? '1' : '0', "\n";
echo 'static1:', empty(S::$one) ? '1' : '0', "\n";

class M
{
    public function __isset($n)
    {
        return true;
    }

    public function __get($n)
    {
        return 0;
    }
}
echo 'magic:', empty((new M())->x) ? '1' : '0', "\n";

$a = ['x' => 0];
echo 'dim:', empty($a['x']) ? '1' : '0', "\n";
