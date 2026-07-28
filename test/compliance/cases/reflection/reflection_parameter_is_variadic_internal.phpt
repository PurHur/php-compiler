--TEST--
ReflectionParameter::isVariadic() on internal functions (#24461, ext/reflection/php_reflection.c)
--FILE--
<?php
foreach (['strlen', 'array_map', 'call_user_func', 'sprintf', 'pack'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, ' rf=', $rf->isVariadic() ? '1' : '0', "\n";
    foreach ($rf->getParameters() as $p) {
        echo '  ', $p->getName(), '=', $p->isVariadic() ? '1' : '0', "\n";
    }
}
?>
--EXPECT--
strlen rf=0
  string=0
array_map rf=1
  callback=0
  array=0
  arrays=1
call_user_func rf=1
  callback=0
  args=1
sprintf rf=1
  format=0
  values=1
pack rf=1
  format=0
  values=1
