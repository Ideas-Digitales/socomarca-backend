<?php

namespace App\Support\Scramble;

use Dedoc\Scramble\Contracts\OperationTransformer;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Support\Str;

/**
 * Documents the Spatie `permission:` middleware of a route: the required permissions in the
 * description and the 403 response rendered for Spatie's UnauthorizedException (bootstrap/app.php).
 */
class DocumentPermissionMiddleware implements OperationTransformer
{
    public function handle(Operation $operation, RouteInfo $routeInfo)
    {
        $permissions = collect($routeInfo->route->gatherMiddleware())
            ->filter(fn ($middleware) => is_string($middleware) && Str::startsWith($middleware, 'permission:'))
            ->flatMap(fn (string $middleware) => explode('|', Str::after($middleware, 'permission:')))
            ->unique()
            ->values();

        if ($permissions->isEmpty()) {
            return;
        }

        $list = $permissions->map(fn (string $permission) => "`{$permission}`")->join(', ', ' or ');
        $requirement = $permissions->count() === 1
            ? "Requires the {$list} permission."
            : "Requires one of the {$list} permissions.";

        $operation->description(trim($operation->description . "\n\n" . $requirement));

        if (!$this->hasResponse($operation, 403)) {
            $operation->addResponse($this->forbiddenResponse());
        }
    }

    private function hasResponse(Operation $operation, int $status): bool
    {
        return collect($operation->responses)->contains(function ($response) use ($status) {
            $code = $response instanceof Reference ? $response->resolve()?->code : $response->code;

            return (string) $code === (string) $status;
        });
    }

    private function forbiddenResponse(): Response
    {
        $body = (new ObjectType)
            ->addProperty('message', (new StringType)->example('You do not have permission.'))
            ->setRequired(['message']);

        return Response::make(403)
            ->setDescription('The user does not have the required permission')
            ->setContent('application/json', Schema::fromType($body));
    }
}
