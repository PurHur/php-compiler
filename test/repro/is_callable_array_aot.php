<?php

declare(strict_types=1);

/**
 * AOT is_callable() array callables — [object, method] / [Class, static] (#27173).
 *
 * php-src: Zend/zend_execute_API.c — zend_is_callable_ex
 */
echo is_callable('strlen') ? 'str=y' : 'str=n', "\n";
echo is_callable([new DateTime(), 'format']) ? 'arr=y' : 'arr=n', "\n";
echo is_callable(['DateTime', 'createFromFormat']) ? 'static=y' : 'static=n', "\n";
