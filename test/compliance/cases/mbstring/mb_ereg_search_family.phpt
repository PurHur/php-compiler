--TEST--
stdlib mb_ereg_search*/match/eregi_replace/replace_callback (ext/mbstring/php_mbregex.c, #20024)
--FILE--
<?php
declare(strict_types=1);
echo function_exists('mb_ereg_search_init') ? 'yes' : 'no', "\n";
echo function_exists('mb_ereg_search') ? 'yes' : 'no', "\n";
echo function_exists('mb_ereg_match') ? 'yes' : 'no', "\n";
echo function_exists('mb_eregi_replace') ? 'yes' : 'no', "\n";
echo function_exists('mb_ereg_replace_callback') ? 'yes' : 'no', "\n";

mb_ereg_search_init('abc123def', '[0-9]+');
var_export(mb_ereg_search());
echo "\n";
var_export(mb_ereg_search_getregs());
echo "\n";
var_export(mb_ereg_search_getpos());
echo "\n";

mb_ereg_search_init('a1b2c3', '[0-9]');
$walk = [];
while (mb_ereg_search()) {
    $walk[] = mb_ereg_search_getregs()[0];
}
var_export($walk);
echo "\n";

mb_ereg_search_init('hello world', 'world');
var_export(mb_ereg_search_pos());
echo "\n";

mb_ereg_search_init('hello', '.');
mb_ereg_search_setpos(-1);
var_export(mb_ereg_search_getpos());
echo "\n";

var_export(mb_ereg_match('he.*o', 'hello'));
echo "\n";
var_export(mb_ereg_match('[0-9]+', 'abc123'));
echo "\n";
var_export(mb_ereg_match('[0-9]+', '123abc'));
echo "\n";

var_export(mb_eregi_replace('WORLD', 'Earth', 'Hello World'));
echo "\n";
var_export(mb_ereg_replace_callback('W.', static function (array $m): string {
    return strtoupper($m[0]);
}, 'Hello World'));
echo "\n";
?>
--EXPECT--
yes
yes
yes
yes
yes
true
array (
  0 => '123',
)
6
array (
  0 => '1',
  1 => '2',
  2 => '3',
)
array (
  0 => 6,
  1 => 5,
)
4
true
false
true
'Hello Earth'
'Hello WOrld'
