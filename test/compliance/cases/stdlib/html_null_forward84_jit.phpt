--TEST--
JIT: htmlspecialchars/htmlentities decode family soft-null on 8.4 (#21180, ext/standard/html.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--FILE--
<?php
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach ([
    'htmlspecialchars' => static fn () => htmlspecialchars(null),
    'htmlentities' => static fn () => htmlentities(null),
    'htmlspecialchars_decode' => static fn () => htmlspecialchars_decode(null),
    'html_entity_decode' => static fn () => html_entity_decode(null),
] as $label => $factory) {
    try {
        $r = $factory();
        echo "$label: uncaught ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $label.': '.$e->getMessage()."\n";
    }
}
--EXPECT--
DEP
htmlspecialchars: uncaught ''
DEP
htmlentities: uncaught ''
DEP
htmlspecialchars_decode: uncaught ''
DEP
html_entity_decode: uncaught ''
