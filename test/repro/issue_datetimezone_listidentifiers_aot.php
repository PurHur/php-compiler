<?php
// DateTimeZone::listIdentifiers under thin AOT — expect ['UTC'] (peer timezone_identifiers_list works)
$a = DateTimeZone::listIdentifiers(DateTimeZone::UTC);
echo 'ok:', (is_array($a) && count($a) === 1 && $a[0] === 'UTC') ? '1' : '0', "\n";
echo 'type=', gettype($a), "\n";
