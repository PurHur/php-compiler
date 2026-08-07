<?php
/**
 * #28691 — excess argc → ArgumentCountError (Zend), not LogicException.
 *
 * php-src: ext/standard/basic_functions.stub.php / array.stub.php
 */
error_reporting(E_ALL);
$cases = [
    'microtime' => static function () {
        microtime(true, 'x');
    },
    'hrtime' => static function () {
        hrtime(true, 'x');
    },
    'sleep' => static function () {
        sleep(0, 'x');
    },
    'usleep' => static function () {
        usleep(1, 'x');
    },
    'parse_url' => static function () {
        parse_url('http://a', -1, 'x');
    },
    'http_build_query' => static function () {
        http_build_query([], '', '&', PHP_QUERY_RFC1738, 'x');
    },
    'array_column' => static function () {
        array_column([], 'a', null, 'x');
    },
    'array_combine' => static function () {
        array_combine([1], [2], 'x');
    },
    'microtime_ok' => static function () {
        return is_float(microtime(true)) ? 'float' : 'other';
    },
    'parse_url_ok' => static function () {
        $p = parse_url('http://a/b');
        return is_array($p) ? ($p['host'] ?? '') : 'fail';
    },
    'array_combine_ok' => static function () {
        $a = array_combine(['k'], ['v']);
        return is_array($a) ? ($a['k'] ?? '') : 'fail';
    },
];

foreach ($cases as $name => $fn) {
    try {
        $r = $fn();
        echo $name, ':OK:', (string) $r, "\n";
    } catch (Throwable $e) {
        echo $name, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
