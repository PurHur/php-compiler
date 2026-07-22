--TEST--
ReflectionClass::getTraits() returns name => ReflectionClass map (#22108)
--FILE--
<?php
declare(strict_types=1);

trait T1
{
    public function a(): int
    {
        return 1;
    }
}

trait T2
{
    public function b(): int
    {
        return 2;
    }
}

class C
{
    use T1, T2;
}

class EmptyTraits
{
}

$r = new ReflectionClass('C');
echo 'method=', method_exists($r, 'getTraits') ? '1' : '0', "\n";
$traits = $r->getTraits();
$keys = array_keys($traits);
sort($keys);
echo 'keys=', json_encode($keys), "\n";
echo 'T1=', $traits['T1']->getName(), "\n";
echo 'T2=', $traits['T2']->getName(), "\n";
echo 'T1isRC=', ($traits['T1'] instanceof ReflectionClass) ? '1' : '0', "\n";
echo 'empty=', json_encode(array_keys((new ReflectionClass('EmptyTraits'))->getTraits())), "\n";
?>
--EXPECT--
method=1
keys=["T1","T2"]
T1=T1
T2=T2
T1isRC=1
empty=[]
