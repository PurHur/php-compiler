--TEST--
DateTime::format()/date_format(null) soft-null DEP+'' on 8.4 forward profile (#21536, reverts #20693; ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach ([
    'DateTime::format' => static fn () => (new DateTime('2020-01-01'))->format(null),
    'DateTimeImmutable::format' => static fn () => (new DateTimeImmutable('2020-01-01'))->format(null),
    'date_format' => static fn () => date_format(date_create('2020-01-01'), null),
] as $name => $call) {
    try {
        $r = $call();
        echo "{$name}: OK ", var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo "{$name}: TypeError\n";
    }
}
?>
--EXPECT--
DEP
DateTime::format: OK ''
DEP
DateTimeImmutable::format: OK ''
DEP
date_format: OK ''
