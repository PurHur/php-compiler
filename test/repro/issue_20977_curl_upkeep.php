<?php
declare(strict_types=1);

/**
 * Issue #20977 — curl_upkeep() real curl_easy_upkeep (php-src-strict).
 */
echo 'exists=', function_exists('curl_upkeep') ? 'yes' : 'no', "\n";

$ch = curl_init();
$ok = curl_upkeep($ch);
echo 'idle_bool=', is_bool($ok) ? 'yes' : 'no', "\n";
echo 'idle_ok=', $ok ? 'true' : 'false', "\n";
echo 'errno_after=', (string) curl_errno($ch), "\n";

try {
    curl_upkeep('x');
    echo "bad=ok\n";
} catch (TypeError $e) {
    echo "bad=type\n";
}
try {
    curl_upkeep();
    echo "argc=ok\n";
} catch (ArgumentCountError $e) {
    echo "argc=err\n";
}

curl_close($ch);
echo "ok\n";
