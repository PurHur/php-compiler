--TEST--
date: var_dump/print_r DateTime* Zend date/timezone wire — no __dt_* (#22462, ext/date/php_date.c)
--FILE--
<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

function dump_prop_names(object $o): string
{
    ob_start();
    var_dump($o);
    $out = (string) ob_get_clean();
    if (str_contains($out, '__dt_')) {
        return 'LEAK';
    }
    $keys = [];
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^\s*\["([^"]+)"(?::"[^"]*":(?:private|protected))?\]=>/', $line, $m)) {
            $keys[] = $m[1];
        }
    }

    return implode(',', $keys);
}

$d = new DateTime('2020-01-01');
echo dump_prop_names($d), "\n";

$i = new DateTimeImmutable('2020-01-01');
echo dump_prop_names($i), "\n";

$z = new DateTimeZone('UTC');
echo dump_prop_names($z), "\n";

ob_start();
print_r($d);
$pr = (string) ob_get_clean();
echo str_contains($pr, '__dt_') ? "print_r_LEAK\n" : "print_r_ok\n";
echo str_contains($pr, '[date]') ? "print_r_has_date\n" : "print_r_no_date\n";

echo 'mangled=', json_encode(array_keys(get_mangled_object_vars($d))), "\n";
?>
--EXPECT--
date,timezone_type,timezone
date,timezone_type,timezone
timezone_type,timezone
print_r_ok
print_r_has_date
mangled=[]
