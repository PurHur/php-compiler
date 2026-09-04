<?php
const ENT_NOQUOTES_L = 0;
const ENT_QUOTES_L = 3;
$allowQuotes = false;
$t1 = $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES;
echo "assigned=$t1\n";
echo "inline_echo=" . ($allowQuotes ? ENT_NOQUOTES : ENT_QUOTES) . "\n";
echo "lit_false_echo=" . (false ? ENT_NOQUOTES : ENT_QUOTES) . "\n";

function show($n, $v) { echo "$n=$v\n"; }
show('assigned_arg', $t1);
show('inline_arg', $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES);
show('lit_false_arg', false ? ENT_NOQUOTES : ENT_QUOTES);
show('lit_true_arg', true ? ENT_NOQUOTES : ENT_QUOTES);

// htmlspecialchars specifically
$s = 'a"b';
echo 'hs_inline=' . htmlspecialchars($s, $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES, 'UTF-8') . "\n";
echo 'hs_assigned=' . htmlspecialchars($s, $t1, 'UTF-8') . "\n";
echo 'hs_2arg_inline=' . htmlspecialchars($s, $allowQuotes ? ENT_NOQUOTES : ENT_QUOTES) . "\n";

// other builtins
echo 'str_pad=' . str_pad('x', $allowQuotes ? 1 : 5, '-') . "\n";
echo 'max=' . max($allowQuotes ? 1 : 5, 2) . "\n";
