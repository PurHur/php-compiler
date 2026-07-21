<?php

declare(strict_types=1);

echo class_exists('mysqli_sql_exception') ? "mysqli_sql_exception=yes\n" : "mysqli_sql_exception=no\n";
echo class_exists('mysqli_driver') ? "mysqli_driver=yes\n" : "mysqli_driver=no\n";
echo function_exists('mysqli_init') ? "mysqli_init=yes\n" : "mysqli_init=no\n";

$init = mysqli_init();
echo $init instanceof mysqli ? "mysqli_init_object=yes\n" : "mysqli_init_object=no\n";

$direct = new mysqli();
echo $direct instanceof mysqli ? "new_mysqli_object=yes\n" : "new_mysqli_object=no\n";
