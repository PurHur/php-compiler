<?php
/**
 * PHPUnit-free Parsedown fixture runner (#36380).
 *
 * Mirrors ParsedownTest::test_ against test/data/*.md|.html pairs.
 * Extension / rawHtml cases are skipped (need SampleExtensions + PHPUnit).
 */
require __DIR__ . '/pinned/Parsedown.php';
require __DIR__ . '/pinned/test/TestParsedown.php';

$Parsedown = new TestParsedown();
$dir = __DIR__ . '/pinned/test/data/';

$pass = 0;
$fail = 0;
$skip = 0;
$failures = [];

$mds = glob($dir . '*.md') ?: [];
sort($mds, SORT_STRING);

foreach ($mds as $mdPath) {
    $test = basename($mdPath, '.md');
    $htmlPath = $dir . $test . '.html';
    if (!is_file($htmlPath)) {
        $skip++;
        continue;
    }

    // XSS / strict fixtures flip Parsedown modes (same as ParsedownTest).
    $Parsedown->setSafeMode(substr($test, 0, 3) === 'xss');
    $Parsedown->setStrictMode(substr($test, 0, 6) === 'strict');

    $markdown = file_get_contents($mdPath);
    $expected = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($htmlPath));
    $actual = $Parsedown->text($markdown);

    if ($actual === $expected) {
        $pass++;
    } else {
        $fail++;
        $failures[] = $test;
        if (count($failures) <= 8) {
            fwrite(STDERR, "FAIL $test\n");
        }
    }
}

echo "SUMMARY pass=$pass fail=$fail skip=$skip total=" . ($pass + $fail + $skip) . "\n";
if ($failures !== []) {
    echo 'FAILURES ' . implode(',', array_slice($failures, 0, 32)) . "\n";
}
exit($fail === 0 ? 0 : 1);
