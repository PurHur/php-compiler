<?php
echo 'timezone_version_get: ', function_exists('timezone_version_get') ? 'yes' : 'NO', "\n";
$version = timezone_version_get();
echo is_string($version) && '' !== $version ? "version_ok\n" : "version_bad\n";
echo $version, "\n";
