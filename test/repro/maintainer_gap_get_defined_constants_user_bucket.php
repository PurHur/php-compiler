<?php
declare(strict_types=1);

$c = get_defined_constants(true);
echo 'user_bucket='.(array_key_exists('user', $c) ? 'yes' : 'no')."\n";
if (extension_loaded('iconv')) {
    echo isset($c['iconv']['ICONV_MIME_DECODE_STRICT']) ? "iconv_ok\n" : "iconv_bad\n";
}
