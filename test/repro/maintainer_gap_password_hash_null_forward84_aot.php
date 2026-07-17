<?php
// #20174 AOT smoke — uncaught TypeError (exit 255), php-src Z_PARAM_STR
password_hash(null, PASSWORD_DEFAULT);
