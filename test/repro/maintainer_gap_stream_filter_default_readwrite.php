<?php
declare(strict_types=1);

function roundtrip(string $label, callable $attach): void
{
    $fp = fopen('php://memory', 'r+');
    $attach($fp);
    fwrite($fp, 'test');
    rewind($fp);
    echo $label, ': ', stream_get_contents($fp), "\n";
    fclose($fp);
}

roundtrip('append_default_rot13', static function ($fp): void {
    stream_filter_append($fp, 'string.rot13');
});
roundtrip('prepend_default_rot13', static function ($fp): void {
    stream_filter_prepend($fp, 'string.rot13');
});
roundtrip('append_default_base64', static function ($fp): void {
    stream_filter_append($fp, 'convert.base64-encode');
});
