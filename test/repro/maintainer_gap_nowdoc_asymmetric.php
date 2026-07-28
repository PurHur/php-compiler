<?php
// #24460 — nowdoc body with asymmetric-visibility text must stay a plain string (Zend ST_NOWDOC).
$s = <<<'EOT'
public private(set) string $x;
EOT;
echo 'ok len=' . strlen($s) . "\n";
