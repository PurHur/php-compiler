--TEST--
stdlib phpcredits named flags: + bool return (#24508, ext/standard/info.c)
--FILE--
<?php
$rf = new ReflectionFunction('phpcredits');
echo $rf->getParameters()[0]->getName(), "\n";
echo $rf->hasReturnType() ? (string) $rf->getReturnType() : '(none)', "\n";
ob_start();
$ok = phpcredits(flags: CREDITS_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'PHP Credits') ? "credits ok\n" : "credits missing\n";
var_dump($ok);
try {
    phpcredits(flag: CREDITS_GENERAL);
    echo "legacy_flag_accepted\n";
} catch (Error $e) {
    echo str_contains($e->getMessage(), 'flag') ? "legacy rejected\n" : $e->getMessage(), "\n";
}
--EXPECT--
flags
bool
credits ok
bool(true)
legacy rejected
