--TEST--
stdlib preg_replace/filter/callback Reflection return array|string|null (#27813, #28897)
--FILE--
<?php
declare(strict_types=1);
foreach (['preg_replace', 'preg_filter', 'preg_replace_callback'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
echo 'named=', preg_replace(pattern: '/a/', replacement: 'b', subject: 'a'), "\n";
?>
--EXPECT--
preg_replace ret=array|string|null
preg_filter ret=array|string|null
preg_replace_callback ret=array|string|null
named=b
