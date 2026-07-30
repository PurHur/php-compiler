--TEST--
ReflectionFunction::getStaticVariables includes closure use captures (#25558, ext/reflection/php_reflection.c)
--FILE--
<?php
$a = 1;
$b = 'x';
$f = function () use ($a, $b) {
    return $a . $b;
};
$sv = (new ReflectionFunction($f))->getStaticVariables();
ksort($sv);
echo json_encode($sv), "\n";

$n = 10;
$g = function () use ($n) {
    static $c = 0;
    $c++;
    return $n + $c;
};
$g();
$sv2 = (new ReflectionFunction($g))->getStaticVariables();
ksort($sv2);
echo json_encode($sv2), "\n";

$ref = 'x';
$h = function () use (&$ref) {
    static $k = 0;
    $k++;
    $ref .= '!';
    return $k;
};
$h();
$sv3 = (new ReflectionFunction($h))->getStaticVariables();
ksort($sv3);
echo json_encode($sv3), "\n";
--EXPECT--
{"a":1,"b":"x"}
{"c":1,"n":10}
{"k":1,"ref":"x!"}
