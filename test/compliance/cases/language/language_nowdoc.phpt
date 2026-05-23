--TEST--
Language: nowdoc without interpolation (issue #178)
--FILE--
<?php
$name = 'ignored';
echo <<<'TAG'
literal {$name}

TAG;

echo <<<'EOT'
line two

EOT;
--EXPECT--
literal {$name}
line two
