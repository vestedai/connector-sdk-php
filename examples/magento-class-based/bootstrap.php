<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Vested\Connect\Sdk\ConnectorApp;

return ConnectorApp::create()
    ->withWorkerPoolSize(4)
    ->scanNamespace('Magento\\Connector\\Agents', __DIR__ . '/src/Agents')
    ->scanNamespace('Magento\\Connector\\Tools',  __DIR__ . '/src/Tools')
    ->build();
