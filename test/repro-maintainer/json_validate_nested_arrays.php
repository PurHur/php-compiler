<?php
foreach (['[]' => true, '[1]' => true, '[[]]' => true, '[[1]]' => true] as $json => $expect) {
    $got = json_validate($json);
    echo $json, ' expect=', (int) $expect, ' got=', (int) $got, ($got === $expect ? ' ok' : ' FAIL'), "\n";
}
foreach (['[{}]' => true, '[{"a":1}]' => true] as $json => $expect) {
    $got = json_validate($json);
    echo $json, ' expect=', (int) $expect, ' got=', (int) $got, ($got === $expect ? ' ok' : ' FAIL'), "\n";
}
