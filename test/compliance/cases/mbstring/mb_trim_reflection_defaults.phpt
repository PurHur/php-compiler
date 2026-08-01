--TEST--
mbstring mb_trim/ltrim/rtrim Reflection types + optional defaults (#26283, ext/mbstring/mbstring.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
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
echo 'named=', mb_trim(string: ' x ', characters: null, encoding: 'UTF-8'), "\n";
echo 'omit=', mb_trim(' x '), "\n";
echo 'ltrim=', mb_ltrim(string: ' x '), "\n";
echo 'rtrim=', mb_rtrim(string: ' x '), "\n";
?>
--EXPECT--
mb_trim required=1 argc=3
mb_trim return=string
mb_trim string:string REQ
mb_trim characters:?string OPT=NULL
mb_trim encoding:?string OPT=NULL
mb_ltrim required=1 argc=3
mb_ltrim return=string
mb_ltrim string:string REQ
mb_ltrim characters:?string OPT=NULL
mb_ltrim encoding:?string OPT=NULL
mb_rtrim required=1 argc=3
mb_rtrim return=string
mb_rtrim string:string REQ
mb_rtrim characters:?string OPT=NULL
mb_rtrim encoding:?string OPT=NULL
named=x
omit=x
ltrim=x 
rtrim= x
