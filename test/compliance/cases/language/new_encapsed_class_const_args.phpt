--TEST--
new Foo("x$v", Class::CONST) preserves encapsed arg — ARG_SEND order (#22971)
--FILE--
<?php
class T
{
    public $a;
    public function __construct(...$x)
    {
        $this->a = $x;
    }
}

class C
{
    public const X = 42;
    public const NEG = -1;
}

$cal = 'hebrew';
var_export((new T("en_US@calendar=$cal", C::NEG, 0))->a);
echo "\n";
var_export((new T("en_US@calendar=$cal", -1, 0))->a);
echo "\n";
$loc = "en_US@calendar=$cal";
var_export((new T($loc, C::NEG, 0))->a);
echo "\n";
var_export((new T("a$cal", C::X, 1))->a);
echo "\n";

function f(...$x)
{
    return $x;
}
var_export(f("a$cal", C::X, 1));
echo "\n";
--EXPECT--
array (
  0 => 'en_US@calendar=hebrew',
  1 => -1,
  2 => 0,
)
array (
  0 => 'en_US@calendar=hebrew',
  1 => -1,
  2 => 0,
)
array (
  0 => 'en_US@calendar=hebrew',
  1 => -1,
  2 => 0,
)
array (
  0 => 'ahebrew',
  1 => 42,
  2 => 1,
)
array (
  0 => 'ahebrew',
  1 => 42,
  2 => 1,
)
