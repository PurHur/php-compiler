<?php
/**
 * #28316 AOT probe — uncaught excess argc.
 * Expect: ArgumentCountError with Zend message, non-zero exit.
 */
urlencode('a', 'x');
