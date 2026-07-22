<?php
// Repro #21895 — mysqli_execute_query / mysqli::execute_query registration
echo 'mysqli_execute_query=', function_exists('mysqli_execute_query') ? 'yes' : 'NO', "\n";
echo 'mysqli::execute_query=', method_exists('mysqli', 'execute_query') ? 'yes' : 'NO', "\n";
