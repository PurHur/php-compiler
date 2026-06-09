<?php
var_dump(function_exists('mb_check_encoding'));
var_dump(mb_check_encoding('café', 'UTF-8'));
var_dump(mb_check_encoding("\xFF", 'UTF-8'));
