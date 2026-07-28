--TEST--
Language: nowdoc/heredoc containing asymmetric visibility tokens are plain strings (#24460, Zend/zend_language_scanner.l)
--FILE--
<?php
$s = <<<'EOT'
public private(set) string $x;
EOT;
echo 'nowdoc:' . strlen($s) . "\n";
$h = <<<EOT
public private(set) string $y;
EOT;
echo "heredoc:ok\n";
--EXPECTF--
%Anowdoc:30
%Aheredoc:ok
