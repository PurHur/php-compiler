<?php
// #24460 — heredoc body with asymmetric-visibility text must not parse-fatal (Zend ST_HEREDOC).
$s = <<<EOT
public private(set) string $x;
EOT;
echo "ok\n";
