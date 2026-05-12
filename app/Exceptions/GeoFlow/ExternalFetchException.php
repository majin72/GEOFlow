<?php

declare(strict_types=1);

namespace App\Exceptions\GeoFlow;

use RuntimeException;

/**
 * 外部浏览器抓取（External Fetch）链路异常。
 *
 * 抛出场景：
 * - Service 未启用却被调用
 * - Bridge 端点未配置或不可达
 * - HTTP 调用失败（连接超时、网络异常、非 2xx 响应）
 * - Bridge 返回的 JSON 缺失关键字段或不是合法 JSON 对象
 *
 * 通常需要让上游业务流程（UrlImportProcessingService）捕获后决定：
 * 是回退到普通抓取、整体失败、还是进重试队列。
 *
 * @see docs/external-fetch-plan.md 完整方案
 */
class ExternalFetchException extends RuntimeException
{
}
