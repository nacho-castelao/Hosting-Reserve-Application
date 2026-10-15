<?php

declare(strict_types=1);

// Front controller: única puerta de entrada PHP de la aplicación.

require dirname(__DIR__) . '/vendor/autoload.php';

(new App\Core\App(dirname(__DIR__)))->run();
