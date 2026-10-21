<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RecordAuditLog
{
    /**
     * Record writes, downloads and refused requests in `audit_logs`.
     *
     * Plain page views are left out: every Inertia visit and partial reload
     * would otherwise land here. Neither the body nor the query string is
     * kept, so no declared amount ever reaches the table.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Read before the request runs: a logout leaves no user behind it.
        $userBefore = $request->user();

        $response = $next($request);

        if (! $this->isAudited($request, $response)) {
            return $response;
        }

        // Read after: a login only has its user once the callback ran.
        $user = $request->user() ?? $userBefore;

        // The action has already run: a failed trace is reported, never turned
        // into an error page over a write that did succeed.
        rescue(fn () => AuditLog::create([
            'user_id' => $user?->id,
            'pharmacy_id' => $user?->current_pharmacy_id,
            'method' => $request->method(),
            'route_name' => $request->route()?->getName(),
            'path' => Str::limit($request->path(), 2048, ''),
            'status' => $response->getStatusCode(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]));

        return $response;
    }

    private function isAudited(Request $request, Response $response): bool
    {
        if (! $request->isMethodSafe()) {
            return true;
        }

        if (in_array($response->getStatusCode(), [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
            return true;
        }

        return Str::endsWith((string) $request->route()?->getName(), '.download');
    }
}
