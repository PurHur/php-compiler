<?php
// #22716 — debug_zval_dump() interned string marker (ZSTR_IS_INTERNED)
$a = 'hi';
debug_zval_dump($a);
