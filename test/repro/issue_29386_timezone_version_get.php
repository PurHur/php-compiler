<?php
// Repro #29386 — timezone_version_get() must not stay on 0.system when zoneinfo is present.
$version = timezone_version_get();
echo 'version=', $version, "\n";
echo '0.system' === $version ? "FAIL_SENTINEL\n" : "OK_NON_SENTINEL\n";
echo (is_string($version) && '' !== $version) ? "OK_NONEMPTY\n" : "FAIL_EMPTY\n";
