--TEST--
date/gmdate/strtotime Reflection timestamp ?int=null (#24845, ext/date/php_date.stub.php)
--FILE--
<?php
foreach (['date', 'gmdate', 'strtotime'] as $f) {
    echo "== $f ==\n";
    $r = new ReflectionFunction($f);
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), ' opt=', (int) $p->isOptional();
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        } else {
            echo ' def=n/a';
        }
        echo ' type=', $p->hasType() ? (string) $p->getType() : 'none';
        echo "\n";
    }
}
$omit = date('Y-m-d');
$zero = date('Y-m-d', 0);
echo 'omit_not_epoch=', ($omit !== $zero) ? '1' : '0', "\n";
echo 'omit_matches_now=', ($omit === date('Y-m-d')) ? '1' : '0', "\n";
?>
--EXPECT--
== date ==
format opt=0 def=n/a type=string
timestamp opt=1 def=NULL type=?int
== gmdate ==
format opt=0 def=n/a type=string
timestamp opt=1 def=NULL type=?int
== strtotime ==
datetime opt=0 def=n/a type=string
baseTimestamp opt=1 def=NULL type=?int
omit_not_epoch=1
omit_matches_now=1
