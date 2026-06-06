--TEST--
Language: empty() compiles when php-cfg clears Empty_->expr after split dim fetch (#6829)
--FILE--
<?php
function empty_array_dim(array $hooks): bool {
    return empty($hooks['get']);
}

function empty_after_assign(object $op): bool {
    $parentKeywordScope = $op->flag ?? false;
    if (!$parentKeywordScope) {
        return true;
    }
    return false;
}

var_export(empty_array_dim([]));
echo "\n";
var_export(empty_array_dim(['get' => 1]));
echo "\n";
var_export(empty_after_assign((object) ['flag' => false]));
echo "\n";
--EXPECT--
true
false
true
