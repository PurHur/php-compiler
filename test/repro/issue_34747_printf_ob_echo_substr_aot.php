<?php
/**
 * AOT: printf must link and match Zend after ObOutput lazy (#34747 / re-#34695).
 *
 * @see php-src ext/standard/formatted_print.c
 */
printf("d=%d\n", 7);
printf("s=%s\n", 'hi');
printf("f=%.1f\n", 1.5);
