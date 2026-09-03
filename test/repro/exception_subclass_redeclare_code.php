<?php
// #36382: Slim HttpNotFoundException redeclares Exception::$code / $message with new defaults.
class HttpException extends RuntimeException
{
    protected $request;
    protected string $title = '';
}
class HttpSpecializedException extends HttpException
{
}
class HttpNotFoundException extends HttpSpecializedException
{
    protected $code = 404;
    protected $message = 'Not found.';
    protected string $title = '404 Not Found';
}
$defaults = (new ReflectionClass(HttpNotFoundException::class))->getDefaultProperties();
echo 'ok ', $defaults['code'], '|', $defaults['message'], "\n";
