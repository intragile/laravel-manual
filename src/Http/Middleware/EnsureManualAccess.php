<?php

declare(strict_types=1);

namespace IntrAgile\Manual\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal Not part of the public API; it may change in any release.
 */
final class EnsureManualAccess
{
    public function __construct(
        private readonly Config $config,
        private readonly Gate $gate,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $access = $this->config->get('manual.access', 'authenticated');

        if ($access === 'public') {
            return $next($request);
        }

        if (! is_string($access) || $access === 'disabled') {
            abort(404);
        }

        if ($access === 'authenticated') {
            if ($request->user() === null) {
                throw new AuthenticationException;
            }

            return $next($request);
        }

        // Any other value names a gate. The gate is consulted for guests too.
        if ($this->gate->allows($access)) {
            return $next($request);
        }

        if ($request->user() === null) {
            throw new AuthenticationException;
        }

        abort(403);
    }
}
