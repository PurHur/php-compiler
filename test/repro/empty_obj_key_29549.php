<?php
error_reporting(E_ALL);
class O {}
$a = [];
try { var_export(empty($a[new O])); echo "\n"; } catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(), "\n"; }
try { var_export(isset($a[new O])); echo "\n"; } catch (Throwable $e) { echo get_class($e), ":", $e->getMessage(), "\n"; }
