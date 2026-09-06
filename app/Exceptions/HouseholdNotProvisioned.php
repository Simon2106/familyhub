<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Thrown when the database has been migrated but never seeded.
 *
 * This used to surface as a bare 404 from firstOrFail(), which is actively
 * misleading on the wall display: pairing has in fact succeeded, and the only
 * thing missing is the household row. A 503 with the command to run says so.
 */
class HouseholdNotProvisioned extends HttpException
{
    public const FIX = 'php artisan familyhub:seed-demo --household-only';

    public function __construct()
    {
        parent::__construct(503, 'FamilyHub has no household yet. Run: '.self::FIX);
    }

    public function render(Request $request): mixed
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 503);
        }

        return response()->view('errors.not-provisioned', ['fix' => self::FIX], 503);
    }
}
