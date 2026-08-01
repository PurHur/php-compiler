--TEST--
mbstring mb_ucfirst/mb_lcfirst Reflection types + encoding default forward 8.4 (#26282, ext/mbstring/mbstring.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['mb_ucfirst', 'mb_lcfirst'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' required=', $r->getNumberOfRequiredParameters(), ' argc=', $r->getNumberOfParameters(), "\n";
    echo $fn, ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', "\n";
    foreach ($r->getParameters() as $p) {
        echo $fn, ' ', $p->getName();
        if ($p->hasType()) {
            echo ':', $p->getType();
        }
        echo $p->isOptional() ? ' OPT' : ' REQ';
        if ($p->isOptional() && $p->isDefaultValueAvailable()) {
            echo '=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}
echo 'uc_named=', mb_ucfirst(string: 'ab', encoding: 'UTF-8'), "\n";
echo 'uc_omit=', mb_ucfirst('ab'), "\n";
echo 'lc_named=', mb_lcfirst(string: 'ABC', encoding: 'UTF-8'), "\n";
echo 'lc_omit=', mb_lcfirst('ABC'), "\n";
?>
--EXPECT--
mb_ucfirst required=1 argc=2
mb_ucfirst return=string
mb_ucfirst string:string REQ
mb_ucfirst encoding:?string OPT=NULL
mb_lcfirst required=1 argc=2
mb_lcfirst return=string
mb_lcfirst string:string REQ
mb_lcfirst encoding:?string OPT=NULL
uc_named=Ab
uc_omit=Ab
lc_named=aBC
lc_omit=aBC
