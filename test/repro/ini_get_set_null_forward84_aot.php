<?php
/** AOT soft-null ini_get (#21312) — must not TypeError; avoid var_export(false) AOT crash. */
error_reporting(E_ALL & ~E_DEPRECATED);
echo ini_get(null) === false ? "false\n" : "bad\n";
