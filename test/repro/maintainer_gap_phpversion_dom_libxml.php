<?php
declare(strict_types=1);

$core = phpversion();
$dom = phpversion('dom');
echo $dom === '20031129' ? "dom_ok\n" : "dom_bad\n";
echo phpversion('pcre') === $core ? "pcre_ok\n" : "pcre_bad\n";
echo phpversion('nonexistent') === false ? "unknown_ok\n" : "unknown_bad\n";
