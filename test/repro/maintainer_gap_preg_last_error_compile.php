<?php

declare(strict_types=1);

@preg_match('/(/', 'x');
echo 'code='.preg_last_error().' msg='.preg_last_error_msg()."\n";
