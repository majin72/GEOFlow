<?php

declare(strict_types=1);

namespace App\Exceptions\GeoFlow;

use RuntimeException;

/**
 * 调用 GEO Monitor sidecar 失败时抛出。
 */
class GeoMonitorSidecarException extends RuntimeException
{
    /**
     * @param  string  $message  错误说明
     * @param  string  $errorCode  sidecar 业务错误码
     * @param  int  $httpStatus  HTTP 状态码
     * @param  array<string, mixed>  $details  附加详情
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'INTERNAL_ERROR',
        public readonly int $httpStatus = 500,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
