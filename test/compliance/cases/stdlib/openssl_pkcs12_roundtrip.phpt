--TEST--
openssl_pkcs12_read/export/export_to_file() PKCS#12 round-trip (#6420, ext/openssl/pkcs12.c)
--FILE--
<?php
foreach (['openssl_pkcs12_read', 'openssl_pkcs12_export', 'openssl_pkcs12_export_to_file'] as $fn) {
    if (!function_exists($fn)) {
        echo "missing:{$fn}\n";
        exit(1);
    }
}
$fixture = base64_decode(
    'MIIFbwIBAzCCBSUGCSqGSIb3DQEHAaCCBRYEggUSMIIFDjCCAvIGCSqGSIb3DQEHBqCCAuMwggLfAgEAMIIC2AYJKoZIhvcNAQcBMFcGCSqGSIb3DQEFDTBKMCkGCSqGSIb3DQEFDDAcBAi0uOz6KEr+PgICCAAwDAYIKoZIhvcNAgkFADAdBglghkgBZQMEASoEEHarxE6qj8pPury8+FDc9TKAggJwODwGtXOdNM1JdaqnLVZqv3BAdNMBGc9J5MaR301xgoCiJGYNvG9W0z9EleMXdktuaGAk+k4Hft8vtV0bVQI9zHmPDhq//C8QS8XJQQeUxl9KnXE7sm/XRnsvRcVruXvhO9cyhSa+jXhFTwTZOVo5eh8mkliAv1msMQmbTorZbIjVb/JV1xvnq1HUH4YJtBEliDr7bdYv0gYvWUofkXj719qZvEx3EERalCu2bw7VO0H2fq862koCIAln1A20lp5uF3ywUaY8n8OUkTSLKMgRXlV9X6INWpVENnDFtWTXlduzt9qxQvReyVuROEr5v6iol9eENCnlx36ARrnZ6wvUWWjh9DnJAHM9zU8ZEA8qeX1h1BflCJKy+u+jj93hxWSpK0XDHW7WrFgqABw64lYjNSU9nwGe7SZ7tSVDWPtuM9IrHsG5fI/QNCEiiW9KGhV2xVguoDsEtF0IHtz7dSZcsaqMmReroymcaSfGuItpiLW6esNus7sRINsYq+AJbLhETDPmc9mHIStFtHxbz+UzkNkgjC/Nanr8Vi/vT4zlk0/3kt8U9Yn14+JNjFuZ/zo/o95cr9jgkCJDZPRNVsDQ3KegqwhOQzVIC5IptCRODGgDmEOCCVQaJD/42jlwGMtdlDW1oL1Dbx3qMShyt0j9H4CfmyYLiGtcKqOcfjJ8t9n9MtEzMTiDEki0SzMuShgPdYFtiHwoci19InPws6oU9vJQibfoF9KeHo8ZfogawGBXBsG04NfqIBCbcWod/CrAFlebic/wcDORNtdCrnQ3m8Kvz/YbtAFxbM+br1ctt0OrGIZ89YebRnv6ehX2sMZcMIICFAYJKoZIhvcNAQcBoIICBQSCAgEwggH9MIIB+QYLKoZIhvcNAQwKAQKgggHBMIIBvTBXBgkqhkiG9w0BBQ0wSjApBgkqhkiG9w0BBQwwHAQIjvfS6OjYsdYCAggAMAwGCCqGSIb3DQIJBQAwHQYJYIZIAWUDBAEqBBB/viZ4y5xhmKigwJcoDPyhBIIBYOsd44ZuYzcKKtEVCT+Cu55uGeP6vL13s3rOKohNZsuu2OTcqw2S30S8/45msHgm5H05FknKeFEn4o/85poyRCAq75+NQS3YHvxCu7PyVmvFKGQJs+7+pGQfbaWqwwIPtvRdx7rVjqLkjZgwIY2bkaTXq41WZkfXqoIPP5LxhqSArbuKcAhhPqVrOi2LDYs292sRXzmg92KQPKeXVfLaJfoxx1wfHCaUKA3zIvK5LKtPpt/usF2lc3gQ05HRvhWm48cFj45SuXWkymP0Q1gZr9Cks2LVoS1TonruP1Dd4xustjtGRg1HC/CEYMoLNPCapfJplBzLAPo3cEBSBfLWFAo/CFyGpQYRLNJkFhdMdX85mOrSs/5IJiULWYVCMrZ+ih1vvcFlEBOVRKV+FF1ikbBBdNau5MMXc1EUTzHJfjT5zdLWiXRmziYxBVnq/GR7a47Lksmu8yOdM9HE1PV517cxJTAjBgkqhkiG9w0BCRUxFgQUhae+oqe2O5fbyLxmU4KYYoneXNcwQTAxMA0GCWCGSAFlAwQCAQUABCD1XV5Hex6RYgmW730EjHtZ8NYx/qN0/ACktz+ztNliYQQIH7pCt2Kf+moCAggA',
    true
);
$certs = [];
if (!openssl_pkcs12_read($fixture, $certs, 'secret')) {
    echo "read-fail\n";
    exit(1);
}
$cert = $certs['cert'];
$key = $certs['pkey'];
$out = '';
if (!openssl_pkcs12_export($cert, $out, $key, 'secret')) {
    echo "export-fail\n";
    exit(1);
}
$certs2 = [];
if (!openssl_pkcs12_read($out, $certs2, 'secret')) {
    echo "roundtrip-fail\n";
    exit(1);
}
echo isset($certs2['cert'], $certs2['pkey']) ? "roundtrip-ok\n" : "keys-missing\n";
$tmp = sys_get_temp_dir().'/phpc_pkcs12_compliance_'.getmypid().'.p12';
if (!openssl_pkcs12_export_to_file($cert, $tmp, $key, 'secret')) {
    echo "file-fail\n";
    exit(1);
}
$file = file_get_contents($tmp);
@unlink($tmp);
$certs3 = [];
echo is_string($file) && '' !== $file && openssl_pkcs12_read($file, $certs3, 'secret') ? "file-ok\n" : "file-read-fail\n";
?>
--EXPECT--
roundtrip-ok
file-ok
