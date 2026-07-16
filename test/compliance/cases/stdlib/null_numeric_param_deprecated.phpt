--TEST--
stdlib number_format/chr/dechex(null) emit E_DEPRECATED then coerce (#19756)
--FILE--
<?php
error_reporting(E_ALL);

$cases = [
    'number_format' => static fn () => number_format(null),
    'chr' => static fn () => chr(null),
    'dechex' => static fn () => dechex(null),
];

foreach ($cases as $name => $fn) {
    $seen = [];
    set_error_handler(static function (int $no, string $str) use (&$seen): bool {
        $seen[] = [$no, $str];
        return true;
    });
    $result = $fn();
    restore_error_handler();
    $depr = 0;
    $msgOk = 0;
    foreach ($seen as [$no, $str]) {
        if (E_DEPRECATED !== $no) {
            continue;
        }
        $depr = 1;
        if ('number_format' === $name
            && str_contains($str, 'number_format(): Passing null to parameter #1 ($num) of type float is deprecated')
        ) {
            $msgOk = 1;
        } elseif ('chr' === $name
            && str_contains($str, 'chr(): Passing null to parameter #1 ($codepoint) of type int is deprecated')
        ) {
            $msgOk = 1;
        } elseif ('dechex' === $name
            && str_contains($str, 'dechex(): Passing null to parameter #1 ($num) of type int is deprecated')
        ) {
            $msgOk = 1;
        }
    }
    echo $name, ' r=', var_export($result, true), ' depr=', $depr, ' msg=', $msgOk, "\n";
}
?>
--EXPECT--
number_format r='0' depr=1 msg=1
chr r='' . "\0" . '' depr=1 msg=1
dechex r='0' depr=1 msg=1
