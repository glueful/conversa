<?php

/**
 * Conversa routes. $router is provided by ServiceProvider::loadRoutesFrom().
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Conversa\Controllers\MessageController;
use Glueful\Extensions\Conversa\Controllers\WebhookController;

$router->group(['prefix' => '/conversa'], function ($router) {
    $router->post('/messages', [MessageController::class, 'store'])
        ->middleware(['auth', 'rate_limit:60,1']);
    $router->get('/messages', [MessageController::class, 'index'])
        ->middleware(['auth', 'rate_limit:100,1']);

    // Public, signature-verified inside the controller (fail closed).
    $router->get('/webhooks/{provider}', [WebhookController::class, 'verify']);
    $router->post('/webhooks/{provider}', [WebhookController::class, 'handle']);
});
