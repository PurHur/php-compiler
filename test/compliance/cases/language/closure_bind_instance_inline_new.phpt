--TEST--
Language: Closure::bind($closure, new $obj(), $scope) — bound invoke reads $this property (#11857, zend_closures.c)
--FILE--
<?php
declare(strict_types=1);
class T {
    public int $x = 1;
}
$c = function (): int {
    return $this->x;
};
$bound = Closure::bind($c, new T(), T::class);
echo $bound() === 1 ? "ok\n" : "fail\n";
?>
--EXPECT--
ok
