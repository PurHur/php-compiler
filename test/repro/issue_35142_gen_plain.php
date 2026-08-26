<?php
function g(){ yield 1; yield 2; }
foreach(g() as $v) echo $v;
