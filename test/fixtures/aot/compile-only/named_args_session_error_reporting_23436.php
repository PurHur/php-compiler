<?php
// AOT lint: error_reporting/session_name Zend stub named params (#23436).
// Positional AOT for both is pre-existing red on master (error_reporting set no-ops;
// session_name missing __phpc_session_name_apply). VM/JIT cover runtime.
error_reporting(error_level: E_ERROR);
session_name(name: 'PHPSESSID');
