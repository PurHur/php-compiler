<?php
// Repro for #17429 — usort/uasort/uksort direction: SortDirection on PHP 8.4 profile.
declare(strict_types=1);

$a = [3, 1, 2];
usort($a, 'strcmp', direction: SortDirection::Ascending);
echo 'usort asc: ', implode(',', $a), "\n";

$b = [3, 1, 2];
usort($b, 'strcmp', direction: SortDirection::Descending);
echo 'usort desc: ', implode(',', $b), "\n";

$c = ['b' => 2, 'a' => 1, 'c' => 3];
uasort($c, 'strcmp', direction: SortDirection::Ascending);
echo 'uasort asc: ', implode(',', array_keys($c)), "\n";

$d = ['b' => 2, 'a' => 1, 'c' => 3];
uasort($d, 'strcmp', direction: SortDirection::Descending);
echo 'uasort desc: ', implode(',', array_keys($d)), "\n";

$e = ['b' => 2, 'a' => 1, 'c' => 3];
uksort($e, 'strcmp', direction: SortDirection::Ascending);
echo 'uksort asc: ', implode(',', array_keys($e)), "\n";

$f = ['b' => 2, 'a' => 1, 'c' => 3];
uksort($f, 'strcmp', direction: SortDirection::Descending);
echo 'uksort desc: ', implode(',', array_keys($f)), "\n";
