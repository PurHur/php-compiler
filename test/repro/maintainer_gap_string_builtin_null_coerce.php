<?php
// Regression guard: #18611 wrongly TypeError'd null on Z_PARAM_STR builtins — Zend 8.2 coerces (non-strict caller).

$checks = [
    ['trim(null)', trim(null)],
    ['html_entity_decode(null)', html_entity_decode(null)],
    ['explode(",", null)', explode(',', null)],
    ['substr(null, 0)', substr(null, 0)],
    ['json_decode(null)', json_decode(null)],
];
foreach ($checks as [$label, $result]) {
    echo $label, ' => ';
    var_export($result);
    echo "\n";
}
