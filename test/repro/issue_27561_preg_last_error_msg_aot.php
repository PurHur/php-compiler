<?php
/**
 * #27561 — AOT preg_last_error_msg() after bad pattern must print Internal error.
 */
@preg_match('/(/', 'x');
echo preg_last_error_msg();
