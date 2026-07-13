<?php
declare(strict_types=1);

foreach (['openssl_pkcs12_read', 'openssl_pkcs12_export', 'openssl_pkcs12_export_to_file'] as $fn) {
    if (!function_exists($fn)) {
        fwrite(STDERR, "fail: {$fn} missing\n");
        exit(1);
    }
}

$fixture = base64_decode(
    'MIIFbwIBAzCCBSUGCSqGSIb3DQEHAaCCBRYEggUSMIIFDjCCAvIGCSqGSIb3DQEHBqCCAuMwggLfAgEAMIIC2AYJKoZIhvcNAQcBMFcGCSqGSIb3DQEFDTBKMCkGCSqGSIb3DQEFDDAcBAi0uOz6KEr+PgICCAAwDAYIKoZIhvcNAgkFADAdBglghkgBZQMEASoEEHarxE6qj8pPury8+FDc9TKAggJwODwGtXOdNM1JdaqnLVZqv3BAdNMBGc9J5MaR301xgoCiJGYNvG9W0z9EleMXdktuaGAk+k4Hft8vtV0bVQI9zHmPDhq//C8QS8XJQQeUxl9KnXE7sm/XRnsvRcVruXvhO9cyhSa+jXhFTwTZOVo5eh8mkliAv1msMQmbTorZbIjVb/JV1xvnq1HUH4YJtBEliDr7bdYv0gYvWUofkXj719qZvEx3EERalCu2bw7VO0H2fq862koCIAln1A20lp5uF3ywUaY8n8OUkTSLKMgRXlV9X6INWpVENnDFtWTXlduzt9qxQvReyVuROEr5v6iol9eENCnlx36ARrnZ6wvUWWjh9DnJAHM9zU8ZEA8qeX1h1BflCJKy+u+jj93hxWSpK0XDHW7WrFgqABw64lYjNSU9nwGe7SZ7tSVDWPtuM9IrHsG5fI/QNCEiiW9KGhV2xVguoDsEtF0IHtz7dSZcsaqMmReroymcaSfGuItpiLW6esNus7sRINsYq+AJbLhETDPmc9mHIStFtHxbz+UzkNkgjC/Nanr8Vi/vT4zlk0/3kt8U9Yn14+JNjFuZ/zo/o95cr9jgkCJDZPRNVsDQ3KegqwhOQzVIC5IptCRODGgDmEOCCVQaJD/42jlwGMtdlDW1oL1Dbx3qMShyt0j9H4CfmyYLiGtcKqOcfjJ8t9n9MtEzMTiDEki0SzMuShgPdYFtiHwoci19InPws6oU9vJQibfoF9KeHo8ZfogawGBXBsG04NfqIBCbcWod/CrAFlebic/wcDORNtdCrnQ3m8Kvz/YbtAFxbM+br1ctt0OrGIZ89YebRnv6ehX2sMZcMIICFAYJKoZIhvcNAQcBoIICBQSCAgEwggH9MIIB+QYLKoZIhvcNAQwKAQKgggHBMIIBvTBXBgkqhkiG9w0BBQ0wSjApBgkqhkiG9w0BBQwwHAQIjvfS6OjYsdYCAggAMAwGCCqGSIb3DQIJBQAwHQYJYIZIAWUDBAEqBBB/viZ4y5xhmKigwJcoDPyhBIIBYOsd44ZuYzcKKtEVCT+Cu55uGeP6vL13s3rOKohNZsuu2OTcqw2S30S8/45msHgm5H05FknKeFEn4o/85poyRCAq75+NQS3YHvxCu7PyVmvFKGQJs+7+pGQfbaWqwwIPtvRdx7rVjqLkjZgwIY2bkaTXq41WZkfXqoIPP5LxhqSArbuKcAhhPqVrOi2LDYs292sRXzmg92KQPKeXVfLaJfoxx1wfHCaUKA3zIvK5LKtPpt/usF2lc3gQ05HRvhWm48cFj45SuXWkymP0Q1gZr9Cks2LVoS1TonruP1Dd4xustjtGRg1HC/CEYMoLNPCapfJplBzLAPo3cEBSBfLWFAo/CFyGpQYRLNJkFhdMdX85mOrSs/5IJiULWYVCMrZ+ih1vvcFlEBOVRKV+FF1ikbBBdNau5MMXc1EUTzHJfjT5zdLWiXRmziYxBVnq/GR7a47Lksmu8yOdM9HE1PV517cxJTAjBgkqhkiG9w0BCRUxFgQUhae+oqe2O5fbyLxmU4KYYoneXNcwQTAxMA0GCWCGSAFlAwQCAQUABCD1XV5Hex6RYgmW730EjHtZ8NYx/qN0/ACktz+ztNliYQQIH7pCt2Kf+moCAggA',
    true
);

$certs = [];
if (!openssl_pkcs12_read($fixture, $certs, 'secret')) {
    echo "fail: read\n";
    exit(1);
}
$cert = $certs['cert'];
$key = $certs['pkey'];
$out = '';
if (!openssl_pkcs12_export($cert, $out, $key, 'secret')) {
    echo "fail: export\n";
    exit(1);
}
if ('' === $out) {
    fwrite(STDERR, "fail: openssl_pkcs12_export empty output\n");
    exit(1);
}

$tmp = sys_get_temp_dir().'/phpc_pkcs12_'.getmypid().'.p12';
if (!openssl_pkcs12_export_to_file($cert, $tmp, $key, 'secret')) {
    echo "fail: export_to_file\n";
    exit(1);
}
$file = file_get_contents($tmp);
@unlink($tmp);
if (!is_string($file) || '' === $file) {
    fwrite(STDERR, "fail: export_to_file wrote empty file\n");
    exit(1);
}

$certs2 = [];
if (!openssl_pkcs12_read($out, $certs2, 'secret')) {
    fwrite(STDERR, "fail: re-read exported PKCS12 failed\n");
    exit(1);
}

echo "ok\n";
