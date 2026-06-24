<?php

declare(strict_types=1);

$tmp = tempnam(sys_get_temp_dir(), 'phpc-mime-repro-');
if (false === $tmp) {
    fwrite(STDERR, "tempnam failed\n");
    exit(1);
}
$path = $tmp.'.php';
rename($tmp, $path);
file_put_contents($path, "<?php echo 1;\n");

var_export(mime_content_type($path));
echo "\n";
var_export(mime_content_type('/no/such/phpc-mime-repro-'.bin2hex(random_bytes(4))));
echo "\n";

unlink($path);
