<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (! $request->user() || ! $request->user()->role) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthorized access.'], 401);
            }
            return redirect('/')->with('error', 'Unauthorized access.');
        }

        if (! in_array($request->user()->role, $roles)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'You do not have permission to access this resource.'], 403);
            }
            return redirect('/')->with('error', 'You do not have permission to access this resource.');
        }

        return $next($request);
    }
}