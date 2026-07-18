<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

/**
 * Pdo\Mysql class constants (php-src ext/pdo_mysql/php_pdo_mysql_int.h + pdo_mysql.stub.php; #20548).
 *
 * Numeric values match the PDO_USE_MYSQLND enum layout (PDO_ATTR_DRIVER_SPECIFIC = 1000).
 */
final class PdoMysqlConstants
{
    public const ATTR_USE_BUFFERED_QUERY = 1000;

    public const ATTR_LOCAL_INFILE = 1001;

    public const ATTR_INIT_COMMAND = 1002;

    public const ATTR_COMPRESS = 1003;

    public const ATTR_DIRECT_QUERY = 1004;

    public const ATTR_FOUND_ROWS = 1005;

    public const ATTR_IGNORE_SPACE = 1006;

    public const ATTR_SSL_KEY = 1007;

    public const ATTR_SSL_CERT = 1008;

    public const ATTR_SSL_CA = 1009;

    public const ATTR_SSL_CAPATH = 1010;

    public const ATTR_SSL_CIPHER = 1011;

    public const ATTR_SERVER_PUBLIC_KEY = 1012;

    public const ATTR_MULTI_STATEMENTS = 1013;

    public const ATTR_SSL_VERIFY_SERVER_CERT = 1014;

    public const ATTR_LOCAL_INFILE_DIRECTORY = 1015;

    /**
     * @var array<string, int>
     */
    public const CLASS_CONSTANTS = [
        'attr_use_buffered_query' => self::ATTR_USE_BUFFERED_QUERY,
        'attr_local_infile' => self::ATTR_LOCAL_INFILE,
        'attr_init_command' => self::ATTR_INIT_COMMAND,
        'attr_compress' => self::ATTR_COMPRESS,
        'attr_direct_query' => self::ATTR_DIRECT_QUERY,
        'attr_found_rows' => self::ATTR_FOUND_ROWS,
        'attr_ignore_space' => self::ATTR_IGNORE_SPACE,
        'attr_ssl_key' => self::ATTR_SSL_KEY,
        'attr_ssl_cert' => self::ATTR_SSL_CERT,
        'attr_ssl_ca' => self::ATTR_SSL_CA,
        'attr_ssl_capath' => self::ATTR_SSL_CAPATH,
        'attr_ssl_cipher' => self::ATTR_SSL_CIPHER,
        'attr_server_public_key' => self::ATTR_SERVER_PUBLIC_KEY,
        'attr_multi_statements' => self::ATTR_MULTI_STATEMENTS,
        'attr_ssl_verify_server_cert' => self::ATTR_SSL_VERIFY_SERVER_CERT,
        'attr_local_infile_directory' => self::ATTR_LOCAL_INFILE_DIRECTORY,
    ];

    /** @var array<string, string> */
    public const CLASS_CONSTANT_NAMES = [
        'attr_use_buffered_query' => 'ATTR_USE_BUFFERED_QUERY',
        'attr_local_infile' => 'ATTR_LOCAL_INFILE',
        'attr_init_command' => 'ATTR_INIT_COMMAND',
        'attr_compress' => 'ATTR_COMPRESS',
        'attr_direct_query' => 'ATTR_DIRECT_QUERY',
        'attr_found_rows' => 'ATTR_FOUND_ROWS',
        'attr_ignore_space' => 'ATTR_IGNORE_SPACE',
        'attr_ssl_key' => 'ATTR_SSL_KEY',
        'attr_ssl_cert' => 'ATTR_SSL_CERT',
        'attr_ssl_ca' => 'ATTR_SSL_CA',
        'attr_ssl_capath' => 'ATTR_SSL_CAPATH',
        'attr_ssl_cipher' => 'ATTR_SSL_CIPHER',
        'attr_server_public_key' => 'ATTR_SERVER_PUBLIC_KEY',
        'attr_multi_statements' => 'ATTR_MULTI_STATEMENTS',
        'attr_ssl_verify_server_cert' => 'ATTR_SSL_VERIFY_SERVER_CERT',
        'attr_local_infile_directory' => 'ATTR_LOCAL_INFILE_DIRECTORY',
    ];
}
