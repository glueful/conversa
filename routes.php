<?php

declare(strict_types=1);

use Glueful\Extensions\Conversa\Controllers\MessageController;
use Glueful\Extensions\Conversa\Controllers\WebhookController;
use Glueful\Routing\Router;

/** @var Router $router Router instance injected by ServiceProvider::loadRoutesFrom() */

$router->group(['prefix' => '/conversa'], function (Router $router) {
    // Message sending and log
    $router->post('/messages', [MessageController::class, 'store'])
        ->middleware(['auth', 'conversa_permission:conversa.messages.send', 'rate_limit:60,1']);

    $router->get('/messages', [MessageController::class, 'index'])
        ->middleware(['auth', 'conversa_permission:conversa.messages.read', 'rate_limit:100,1']);

    // Provider webhooks (public; verified inside the controllers)
    $router->get('/webhooks/{provider}', [WebhookController::class, 'verify']);

    $router->post('/webhooks/{provider}', [WebhookController::class, 'handle']);
});
