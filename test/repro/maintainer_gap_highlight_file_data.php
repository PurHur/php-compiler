<?php

declare(strict_types=1);

/**
 * Issue #32104 — highlight_file()/show_source() must honor allow_url_include for data://.
 */
$uri = 'data://text/plain,<?php echo 1;';

echo 'allow_url_include=', ini_get('allow_url_include'), "\n";
echo 'highlight_file=', var_export(highlight_file($uri), true), "\n";
echo 'show_source=', var_export(show_source($uri), true), "\n";
echo 'fgc=', var_export(file_get_contents($uri), true), "\n";
