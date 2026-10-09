<?php

namespace Abigah\SendIt\Push;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Public endpoint apps call to register (and unregister) their APNs device token.
 */
class DeviceController extends Controller
{
    public const TOKEN_RULE = 'regex:/^[0-9a-fA-F]{64,200}$/';

    public function __construct(protected DeviceStore $devices) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', self::TOKEN_RULE],
            'platform' => ['nullable', 'string', 'in:ios,ipados,macos,watchos,visionos'],
            'environment' => ['nullable', 'string', 'in:sandbox,production'],
            'app_version' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:35'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $created = $this->devices->register($data['token'], [
            'platform' => $data['platform'] ?? 'ios',
            'environment' => $data['environment'] ?? 'production',
            'app_version' => $data['app_version'] ?? null,
            'locale' => $data['locale'] ?? null,
            'timezone' => $data['timezone'] ?? null,
        ]);

        return response()->json(['registered' => true], $created ? 201 : 200);
    }

    public function destroy(string $token): Response
    {
        $this->devices->forget($token);

        return response()->noContent();
    }
}
