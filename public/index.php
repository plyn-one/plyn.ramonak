<?php
declare(strict_types=1);

// The front controller: every request the web server cannot answer from a built page comes here.
require dirname(__DIR__) . '/vendor/autoload.php';

$config = Ramonak\Config::load(getenv('RAMONAK_CONFIG') ?: dirname(__DIR__, 2) . '/shared/config.php');
$request = Ramonak\Http\Request::fromGlobals();
(new Ramonak\Kernel($config))->handle($request)->send($request->method === 'HEAD');
