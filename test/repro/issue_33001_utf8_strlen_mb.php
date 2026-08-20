<?php
// #33001: thin AOT mb_strlen / mb_check_encoding after Type drops always-on utf8 ABI shells.
$s = 'café';
echo mb_strlen($s, 'UTF-8'), "\n";
echo mb_check_encoding($s, 'UTF-8') ? '1' : '0', "\n";
echo mb_check_encoding("\xFF", 'UTF-8') ? '1' : '0', "\n";
