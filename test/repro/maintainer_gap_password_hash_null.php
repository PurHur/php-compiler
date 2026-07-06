<?php

declare(strict_types=1);

// Maintainer gap #17023 — password_hash(null) coerces to empty string (ext/standard/password.c Z_PARAM_STR).
$hash = password_hash(null, PASSWORD_DEFAULT);
if (!\is_string($hash) || '' === $hash) {
    fwrite(STDERR, "fail: expected non-empty hash string\n");
    exit(1);
}
if (!password_verify('', $hash)) {
    fwrite(STDERR, "fail: hash does not verify empty password\n");
    exit(1);
}
echo "ok\n";
