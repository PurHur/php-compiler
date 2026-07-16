<?php
// #18655 AOT smoke — uncaught TypeError (exit 255), php-src Z_PARAM_STR
password_needs_rehash(null, PASSWORD_DEFAULT);
