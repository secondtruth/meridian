<?php

declare(strict_types=1);

use Meridian\Services;
use Meridian\Web\App;
use Meridian\Web\Request;

// In php -S router-script mode, let the built-in server deliver real
// files (fonts, favicon) itself.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

(new App(new Services(dirname(__DIR__))))->handle(Request::fromGlobals())->send();
