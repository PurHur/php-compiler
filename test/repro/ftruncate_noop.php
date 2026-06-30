<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
fwrite($fp, 'hello');
$ok = ftruncate($fp, 3);
rewind($fp);
$content = stream_get_contents($fp);
echo $ok ? 'true' : 'false', ' ', strlen($content), ' ', $content, "\n";
