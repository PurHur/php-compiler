<?php
// #36081: {main} script-global reads emit undefined-variable E_WARNING (Zend/zend_execute.c).
echo $missing;
