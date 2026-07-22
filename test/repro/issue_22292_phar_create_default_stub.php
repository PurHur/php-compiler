<?php
/** Issue #22292 — Phar::createDefaultStub matches php-src shortarc stub. */
$s = Phar::createDefaultStub('index.php', 'cli.php');
echo 'len=', strlen($s), "\n";
echo 'intercept=', str_contains($s, 'interceptFileFuncs') ? 'Y' : 'N', "\n";
echo 'webPhar=', str_contains($s, 'webPhar') ? 'Y' : 'N', "\n";
echo 'Extract_Phar=', str_contains($s, 'class Extract_Phar') ? 'Y' : 'N', "\n";
echo 'shebang=', str_starts_with($s, '#!') ? 'Y' : 'N', "\n";
echo 'start=', str_contains($s, "const START = 'index.php'") ? 'Y' : 'N', "\n";
echo 'web=', str_contains($s, "\$web = 'cli.php'") ? 'Y' : 'N', "\n";
echo 'mapPhar_shim=', (str_starts_with($s, "#!/usr/bin/env php") && str_contains($s, 'Phar::mapPhar();')) ? 'Y' : 'N', "\n";
$s2 = Phar::createDefaultStub('foo.php');
echo 'foo_start=', str_contains($s2, "const START = 'foo.php'") ? 'Y' : 'N', "\n";
echo 'foo_web_default=', str_contains($s2, "\$web = 'index.php'") ? 'Y' : 'N', "\n";
