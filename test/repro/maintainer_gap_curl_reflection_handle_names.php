<?php
/**
 * Repro for #23594 — curl_setopt/exec/errno/error/close Reflection names ch → handle.
 *
 * Expected: all functions report 'handle' (not 'ch'), matching php-src ext/curl/curl.stub.php.
 */

$pass = true;
$expect = [
    'curl_setopt' => ['handle', 'option', 'value'],
    'curl_exec'   => ['handle'],
    'curl_errno'  => ['handle'],
    'curl_error'  => ['handle'],
    'curl_close'  => ['handle'],
];

foreach ($expect as $fn => $expectedNames) {
    $rf = new ReflectionFunction($fn);
    $actual = array_map(fn($p) => $p->getName(), $rf->getParameters());
    if ($actual !== $expectedNames) {
        echo "FAIL $fn: got [" . implode(', ', $actual) . "] expected [" . implode(', ', $expectedNames) . "]\n";
        $pass = false;
    }
}

// Named arg dispatch — curl_setopt(handle: …) must work
$ch = curl_init('https://example.com');
try {
    curl_setopt(handle: $ch, option: CURLOPT_RETURNTRANSFER, value: true);
} catch (Throwable $t) {
    echo "FAIL curl_setopt named: " . get_class($t) . ": " . $t->getMessage() . "\n";
    $pass = false;
}
curl_close(handle: $ch);

echo $pass ? "PASS\n" : "FAIL\n";
