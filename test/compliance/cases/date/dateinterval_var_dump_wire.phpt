--TEST--
date: var_dump/print_r/debug_zval_dump DateInterval Zend wire (#22473, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

function dump_prop_names(object $o): string
{
    ob_start();
    var_dump($o);
    $out = (string) ob_get_clean();
    $keys = [];
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^\s*\["([^"]+)"(?::"[^"]*":(?:private|protected))?\]=>/', $line, $m)) {
            $keys[] = $m[1];
        }
    }

    return implode(',', $keys);
}

$i = new DateInterval('P1DT2H');
echo dump_prop_names($i), "\n";

ob_start();
print_r($i);
$pr = (string) ob_get_clean();
echo str_starts_with($pr, 'DateInterval Object') ? "print_r_ok\n" : "print_r_bad\n";
echo str_contains($pr, '[y]') && str_contains($pr, '[d]') && str_contains($pr, '[h]')
    ? "print_r_has_ymd\n"
    : "print_r_missing_ymd\n";
echo str_contains($pr, 'date_string') ? "print_r_has_date_string\n" : "print_r_no_date_string\n";

ob_start();
debug_zval_dump($i);
$dz = (string) ob_get_clean();
echo str_contains($dz, 'object(DateInterval)') ? "dz_ok\n" : "dz_bad\n";
echo str_contains($dz, 'date_string') ? "dz_has_date_string\n" : "dz_no_date_string\n";

$f = DateInterval::createFromDateString('1 day');
echo dump_prop_names($f), "\n";

echo 'gov=', json_encode(get_object_vars($i)), "\n";
?>
--EXPECT--
y,m,d,h,i,s,f,invert,days,from_string
print_r_ok
print_r_has_ymd
print_r_no_date_string
dz_ok
dz_no_date_string
from_string,date_string
gov={"y":0,"m":0,"d":1,"h":2,"i":0,"s":0,"f":0,"invert":0,"days":false,"from_string":false}
