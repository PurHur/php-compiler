<?php

error_reporting(E_ALL);
var_dump(htmlspecialchars("\xC3\x28", ENT_QUOTES | ENT_IGNORE, 'UTF-8'));
var_dump(htmlspecialchars("a\xC3\x28b", ENT_QUOTES | ENT_IGNORE, 'UTF-8'));
echo 'entities=', var_export(htmlentities("\xC3\x28", ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
echo 'sub_hex=', bin2hex(htmlspecialchars("\xC3\x28", ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), "\n";
echo 'eacute=', var_export(htmlspecialchars("\xC3\xA9", ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
echo 'fail=', var_export(htmlspecialchars("\xC3\x28", ENT_QUOTES, 'UTF-8'), true), "\n";
echo 'dyn=', var_export(htmlspecialchars(chr(0xC3).chr(0x28), ENT_QUOTES | ENT_IGNORE, 'UTF-8'), true), "\n";
