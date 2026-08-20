<?php
declare(strict_types=1);

// Fixed SPKAC payload from openssl_spki_new(RSA-512, "phpc-spki-challenge", SHA256).
$spkac = 'MIHHMHMwXDANBgkqhkiG9w0BAQEFAANLADBIAkEAnaK0cDu5h1WJ70McsRz3ibmm8qq6ud6CpL5uuBOJsA4XF0sLV0iI98tMH49iVQiOIPf8jht9urIuFYziMPwIAQIDAQABFhNwaHBjLXNwa2ktY2hhbGxlbmdlMA0GCSqGSIb3DQEBCwUAA0EAgRrNWyJbHnMPJuQDH248x6W3sGVblGGCY8FBPIwI+DKupAFdOO0YyJWmkOvN2iE9doxibhWjgR9WsE6d16Lj+A==';

$pem = openssl_spki_export($spkac);
echo (is_string($pem) && str_contains($pem, 'BEGIN PUBLIC KEY')) ? 'export-ok' : 'export-bad';
echo '|';
var_export(@openssl_spki_export('bad!!!'));
echo "\n";
