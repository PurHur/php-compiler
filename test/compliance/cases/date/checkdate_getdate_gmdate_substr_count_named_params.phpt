--TEST--
date checkdate/getdate/gmdate and substr_count Zend stub named params (#23462, ext/date/php_date.stub.php + ext/standard/string.stub.php)
--FILE--
<?php
foreach (['checkdate', 'getdate', 'gmdate', 'substr_count'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}

echo var_export(checkdate(month: 2, day: 29, year: 2020), true), "\n";
echo getdate(timestamp: 0)['year'], "\n";
echo gmdate(format: 'Y', timestamp: 0), "\n";
echo substr_count(haystack: 'abab', needle: 'ab'), "\n";
?>
--EXPECT--
checkdate:month,day,year
getdate:timestamp
gmdate:format,timestamp
substr_count:haystack,needle,offset,length
true
1970
1970
2
