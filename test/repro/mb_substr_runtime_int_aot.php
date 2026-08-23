<?php

// #34256 — AOT mb_substr/mb_strcut must not SIGSEGV when start/from is a runtime int.
$i = 1;
$s = 'über';
var_dump(mb_substr('über', $i, 2));
var_dump(mb_substr($s, $i, 2));
var_dump(mb_strcut($s, $i, 2));
