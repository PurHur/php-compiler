--TEST--
Language: closures remain valid callbacks after @silence (#23730)
--FILE--
<?php
@strlen(null);

$hits = 0;
set_error_handler(function (int $no, string $str) use (&$hits): bool {
    $hits++;
    echo "handler:$no:$str\n";
    return true;
});
trigger_error('probe', E_USER_WARNING);
echo "hits:$hits\n";

@strlen(null);
$values = [3, 1, 2];
usort($values, function (int $a, int $b): int {
    return $a <=> $b;
});
echo implode(',', $values), "\n";
?>
--EXPECT--
handler:512:probe
hits:1
handler:8192:strlen(): Passing null to parameter #1 ($string) of type string is deprecated
1,2,3
