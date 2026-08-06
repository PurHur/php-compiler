<?php
// Issue #28170 — PHP_SBINDIR Core path constant (PROFILE≥8.4).
echo 'defined=', defined('PHP_SBINDIR') ? '1' : '0', PHP_EOL;
if (defined('PHP_SBINDIR')) {
    echo 'nonempty=', '' !== (string) PHP_SBINDIR ? '1' : '0', PHP_EOL;
}
$core = get_defined_constants(true)['Core'] ?? [];
echo 'in_core=', array_key_exists('PHP_SBINDIR', $core) ? '1' : '0', PHP_EOL;
