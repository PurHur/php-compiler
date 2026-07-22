--TEST--
ReflectionParameter::getPosition() 0-based index (#22285)
--FILE--
<?php
declare(strict_types=1);

function f($a, $b, $c)
{
}

$ps = (new ReflectionFunction('f'))->getParameters();
echo method_exists($ps[1], 'getPosition') ? "getPosition_ok\n" : "getPosition_missing\n";
echo $ps[0]->getPosition(), ',', $ps[1]->getPosition(), ',', $ps[2]->getPosition(), "\n";

class Demo
{
    public function m($x, $y)
    {
    }
}

$mps = (new ReflectionMethod('Demo', 'm'))->getParameters();
echo 'm_', $mps[0]->getPosition(), ',', $mps[1]->getPosition(), "\n";
?>
--EXPECT--
getPosition_ok
0,1,2
m_0,1
