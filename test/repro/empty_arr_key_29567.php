<?php
error_reporting(E_ALL);
$a = [];
try { var_export(empty($a[[]])); echo "\n"; } catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(), "\n"; }
try { var_export(isset($a[[]])); echo "\n"; } catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(), "\n"; }
