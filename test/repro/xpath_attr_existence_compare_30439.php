<?php
$doc = new DOMDocument();
$doc->loadXML('<root><item n="1"/><item n="2"/><item n="3"/></root>');
$xpath = new DOMXPath($doc);

$tests = [
    ['//item[@n]', 3],
    ['//item[@n > 1]', 2],
    ['//item[@n >= 2]', 2],
    ['//item[@n > 0]', 3],
    ['//item[@n < 3]', 2],
    ['//item[@n <= 1]', 1],
    ['//item[@n != 2]', 2],
    ['//item[@n="2"]', 1],
    ['//item[@n=2]', 1],
];

$pass = true;
foreach ($tests as [$expr, $expected]) {
    $result = $xpath->query($expr)->length;
    $ok = $result === $expected;
    if (!$ok) {
        $pass = false;
    }
    echo ($ok ? 'PASS' : 'FAIL') . " {$expr}: {$result} (expected {$expected})\n";
}

$doc2 = new DOMDocument();
$doc2->loadXML('<root><a x="1"/><b/><a x="2"/></root>');
$xp2 = new DOMXPath($doc2);
$mixed = [
    ['//*[@x]', 2],
    ['//*[@x < 2]', 1],
    ['//*[@x != 1]', 1],
    ['//*[@x <= 1]', 1],
];
foreach ($mixed as [$expr, $expected]) {
    $result = $xp2->query($expr)->length;
    $ok = $result === $expected;
    if (!$ok) {
        $pass = false;
    }
    echo ($ok ? 'PASS' : 'FAIL') . " {$expr}: {$result} (expected {$expected})\n";
}

echo $pass ? "\nALL PASS\n" : "\nFAILURES\n";
exit($pass ? 0 : 1);
