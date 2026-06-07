<?php

declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'phpc_mime_repro_');
if (false === $path) {
    fwrite(STDERR, "tempnam failed\n");
    exit(1);
}
file_put_contents($path, "<?php echo 1;\n");
var_export(function_exists('mime_content_type'));
echo PHP_EOL;
var_export(mime_content_type($path));
echo PHP_EOL;
unlink($path);
