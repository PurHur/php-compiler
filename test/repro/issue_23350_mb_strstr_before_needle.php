<?php
// Repro #23350 — mb_strstr family Zend stub before_needle named parameter
$fns = ['mb_strstr', 'mb_stristr', 'mb_strrchr', 'mb_strrichr'];
$ok = true;
foreach ($fns as $fn) {
    $names = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    $ok = $ok && ['haystack', 'needle', 'before_needle', 'encoding'] === $names;
}

$ok = $ok
    && 'a' === mb_strstr(haystack: 'abc', needle: 'b', before_needle: true)
    && 'bc' === mb_strstr(haystack: 'abc', needle: 'b', before_needle: false)
    && 'a' === mb_stristr(haystack: 'aBc', needle: 'b', before_needle: true)
    && 'Bc' === mb_stristr(haystack: 'aBc', needle: 'b', before_needle: false)
    && 'abc' === mb_strrchr(haystack: 'abcb', needle: 'b', before_needle: true)
    && 'b' === mb_strrchr(haystack: 'abcb', needle: 'b', before_needle: false)
    && 'abc' === mb_strrichr(haystack: 'abcB', needle: 'b', before_needle: true)
    && 'B' === mb_strrichr(haystack: 'abcB', needle: 'b', before_needle: false)
    && 'a' === mb_strstr('abc', 'b', true)
    && 'bc' === mb_strstr('abc', 'b', false);

$rejected = false;
try {
    mb_strstr(haystack: 'abc', needle: 'b', part: true);
} catch (Error $e) {
    $rejected = false !== strpos($e->getMessage(), 'Unknown named parameter $part');
}
$ok = $ok && $rejected;

echo $ok ? "ok\n" : "fail\n";
