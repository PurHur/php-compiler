<?php
// Repro #4120: $x = throw ... must compile on VM (php-src zend_compile.c throw as RHS).
$x = throw new Exception('boom');
