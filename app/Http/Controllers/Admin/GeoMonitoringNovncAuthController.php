<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorNovncConfig;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nginx auth_request 用：校验后台管理员是否可访问公网 noVNC。
 */
class GeoMonitoringNovncAuthController extends Controller
{
    /**
     * 已登录且公网 noVNC 已启用时返回 204，否则 401/403。
     */
    public function __invoke(): HttpResponse
    {
        $config = GeoMonitorNovncConfig::fromConfig();

        if (! $config->publicEnabled) {
            return response('', Response::HTTP_FORBIDDEN);
        }

        if (! $config->allowsAdminSession()) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        if (! Auth::guard('admin')->check()) {
            return response('', Response::HTTP_UNAUTHORIZED);
        }

        return response('', Response::HTTP_NO_CONTENT);
    }
}
