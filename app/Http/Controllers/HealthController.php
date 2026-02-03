<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function check()
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'Livefy NFe API',
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'extensions' => [
                'soap' => extension_loaded('soap'),
                'openssl' => extension_loaded('openssl'),
                'curl' => extension_loaded('curl'),
                'dom' => extension_loaded('dom'),
                'zip' => extension_loaded('zip'),
            ]
        ]);
    }
}
