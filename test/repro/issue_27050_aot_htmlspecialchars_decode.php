<?php
// Repro guard for #27050 — AOT htmlspecialchars_decode must match Zend/VM.
$s = $argv[1] ?? '&lt;a&gt;';
echo htmlspecialchars_decode($s), "\n";
echo htmlspecialchars_decode('&amp;&lt;&gt;&quot;&#039;'), "\n";
