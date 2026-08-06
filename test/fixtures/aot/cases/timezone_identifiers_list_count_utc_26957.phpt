--TEST--
AOT timezone_identifiers_list count/foreach/in_array UTC (#26957)
--FILE--
<?php
// Host tzdata size varies; assert positive count, foreach agreement, UTC membership.
// Also guard bare packed-array count() (prior thin-AOT abort class from the issue handoff).
echo count([1, 2, 3]), "\n";
$list = timezone_identifiers_list();
$c = count($list);
$n = 0;
foreach ($list as $tz) {
    $n++;
}
echo ($c > 0) ? "y" : "n", "\n";
echo ($c === $n) ? "y" : "n", "\n";
echo in_array("UTC", $list, true) ? "y" : "n", "\n";
--EXPECT--
3
y
y
y
