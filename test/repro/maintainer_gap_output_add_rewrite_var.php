<?php

declare(strict_types=1);

// Issue #24370 — output_add_rewrite_var injects hidden fields into <form> (url_scanner_ex.re).
// Capture process stdout (do not rely on ob_get_clean — Zend rewrites on final flush).
output_add_rewrite_var('sid', 'abc');
echo '<form action="x.php"><a href="y.php">z</a></form>', "\n";
