<?php

declare(strict_types=1);

/**
 * Native mysqli_sql_exception fallback for VM host bridges (#21803, #22456).
 *
 * php-src: ext/mysqli/mysqli_exception.c / mysqli.stub.php —
 * mysqli_sql_exception extends RuntimeException with protected $sqlstate + getSqlState().
 */
if (!\class_exists('mysqli_sql_exception', false)) {
    class mysqli_sql_exception extends RuntimeException
    {
        /** @var string */
        protected $sqlstate = '00000';

        public function getSqlState(): string
        {
            return (string) $this->sqlstate;
        }
    }
}
