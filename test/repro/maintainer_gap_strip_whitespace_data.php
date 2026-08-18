<?php

declare(strict_types=1);

/**
 * Issue #32104 — php_strip_whitespace() must honor allow_url_include for data://.
 *
 * Zend: empty string + wrapper-disabled Warning; file_get_contents(data://) still works.
 */
$uri = 'data://text/plain,<?php echo 1; //c';

echo 'allow_url_include=', ini_get('allow_url_include'), "\n";
echo 'strip=', var_export(php_strip_whitespace($uri), true), "\n";
echo 'fgc=', var_export(file_get_contents($uri), true), "\n";
