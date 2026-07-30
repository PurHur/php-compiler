--TEST--
idate/getdate Reflection ?int timestamp=null + idate int|false (#25440, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['idate', 'getdate'] as $f) {
    $r = new ReflectionFunction($f);
    echo "== $f ==\n";
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), ' type=', (string) ($p->getType() ?: '-');
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        } elseif ($p->isOptional()) {
            echo ' def=?';
        }
        echo "\n";
    }
    echo 'ret=', (string) ($r->getReturnType() ?: 'NONE'), "\n";
}
echo 'idate_named=', idate(format: 'Y', timestamp: 0), "\n";
echo 'getdate_named=', getdate(timestamp: 0)['year'], "\n";
echo 'idate_null_now=', (idate(format: 'Y', timestamp: null) === (int) date('Y')) ? '1' : '0', "\n";
echo 'getdate_null_now=', (getdate(timestamp: null)['year'] === (int) date('Y')) ? '1' : '0', "\n";
--EXPECT--
== idate ==
format type=string
timestamp type=?int def=NULL
ret=int|false
== getdate ==
timestamp type=?int def=NULL
ret=array
idate_named=1970
getdate_named=1970
idate_null_now=1
getdate_null_now=1
