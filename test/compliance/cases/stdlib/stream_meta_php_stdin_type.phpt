--TEST--
Stdlib: stream_get_meta_data(php://stdin) stream_type matches host inherited fd (#19129)
--FILE--
<?php
declare(strict_types=1);

$meta = stream_get_meta_data(fopen('php://stdin', 'r'));
$type = $meta['stream_type'] ?? '';

$hostFp = @fopen('php://stdin', 'rb');
$expected = 'STDIO';
if (is_resource($hostFp)) {
    $hostMeta = stream_get_meta_data($hostFp);
    if (isset($hostMeta['stream_type']) && is_string($hostMeta['stream_type'])) {
        $expected = $hostMeta['stream_type'];
        if ('generic_socket' === $expected) {
            $expected = 'unix_socket';
        }
    }
    fclose($hostFp);
}

echo $type, "\n";
echo ($type === $expected) ? "ok\n" : "fail\n";
?>
--EXPECT--
STDIO
ok
