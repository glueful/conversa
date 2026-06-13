<?php

declare(strict_types=1);

namespace Glueful\Extensions\Conversa\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

final class RequireConversaPermission implements RouteMiddleware
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $permission = (string) ($params[0] ?? '');
        if ($permission === '') {
            return $this->forbidden();
        }

        $user = $request->attributes->get('auth.user');
        if (!$user instanceof UserIdentity) {
            return $this->forbidden();
        }

        $manager = $this->permissionManager();
        if (!$manager instanceof PermissionManager) {
            return $this->forbidden();
        }

        $context = [
            'roles' => $user->roles(),
            'scopes' => $user->scopes(),
            'tenant_id' => $request->attributes->get('tenant.id'),
            'route_params' => (array) $request->attributes->get('route.params'),
            'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
        ];

        if (!$manager->can($user->id(), $permission, 'conversa.messages', $context)) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function permissionManager(): ?PermissionManager
    {
        $container = $this->context->getContainer();

        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id)) {
                    $manager = $container->get($id);
                    if ($manager instanceof PermissionManager) {
                        return $manager;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function forbidden(): Response
    {
        return Response::error('Forbidden', Response::HTTP_FORBIDDEN, [
            'code' => 'FORBIDDEN',
        ]);
    }
}
