<?php

declare(strict_types=1);

function phpc_aot_all_key_returns_int($k, $v)
{
    return $v == 1 ? 1 : 0;
}

function phpc_aot_any_key_returns_int($k, $v)
{
    return $v == 1 ? 1 : 0;
}

$h = ['a' => 1, 'b' => '1'];
echo array_all_key($h, 'phpc_aot_all_key_returns_int', true) ? 'T' : 'F';
echo array_any_key($h, 'phpc_aot_any_key_returns_int', false) ? 'T' : 'F';
