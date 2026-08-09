<?php
declare(strict_types=1);

/**
 * Issue #29485 — Dom\HTML_NO_DEFAULT_NS + PECL MINIT consts must not land in user.
 */
$c = get_defined_constants(true);
echo array_key_exists('user', $c) ? "has_user\n" : "no_user\n";
$htmlNo = 'Dom\\HTML_NO_DEFAULT_NS';
if (defined($htmlNo)) {
    echo isset($c['dom'][$htmlNo]) ? "dom_ok\n" : "dom_missing\n";
}
if (defined('MESSAGEPACK_OPT_ASSOC')) {
    echo isset($c['msgpack']['MESSAGEPACK_OPT_ASSOC']) ? "msgpack_ok\n" : "msgpack_missing\n";
}
if (defined('YAML_ANY_ENCODING')) {
    echo isset($c['yaml']['YAML_ANY_ENCODING']) ? "yaml_ok\n" : "yaml_missing\n";
}
if (defined('APC_ITER_KEY')) {
    echo isset($c['apcu']['APC_ITER_KEY']) ? "apcu_ok\n" : "apcu_missing\n";
}
