<?php
echo "VE empty:\n";
var_export(new stdClass);
echo "\n---\n";
echo "VE cast:\n";
var_export((object) ['a' => 1, 'b' => 'x']);
echo "\n---\n";
echo "PR empty:\n";
echo print_r(new stdClass, true);
echo "---\n";
echo "PR cast:\n";
echo print_r((object) ['a' => 1], true);
echo "---\n";
echo "VE user:\n";
class VeUser34506 { public $n = 2; }
var_export(new VeUser34506);
echo "\n---\n";
