<?php
// #24115 — AOT preg_match count (was segfault)
var_dump(preg_match('/\d+/', 'ab 12 cd'));
