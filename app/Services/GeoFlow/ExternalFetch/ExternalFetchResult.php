<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\ExternalFetch;

use App\Exceptions\GeoFlow\ExternalFetchException;

/**
 * 外部浏览器抓取（External Fetch）成功响应的值对象。
 *
 * Bridge 端返回结构（详见 docs/external-fetch-plan.md §5.2）：
 *
 *  {
 *    "markdown":   "...",          // 必填，目标页面正文
 *    "format":     "markdown",      // 可选，默认 "markdown"
 *    "node":       "<hostname>",    // 可选，实际处理抓取的本地节点标识
 *    "fetched_at": 1714896000000    // 可选，毫秒或秒级 unix 时间戳
 *  }
 *
 * 本对象只承载 Bridge → Service 之间的契约，不包含业务字段（库名、关键词等）。
 */
final class ExternalFetchResult
{
    /**
     * @param  string  $markdown         Bridge 抓回的 Markdown 内容
     * @param  string  $format           响应格式标识；目前只有 "markdown"
     * @param  string  $node             实际处理该请求的本地节点标识（空表示未提供）
     * @param  int     $fetchedAtMillis  抓取完成时间戳（毫秒级 unix time，0 表示未提供）
     */
    public function __construct(
        public readonly string $markdown,
        public readonly string $format,
        public readonly string $node,
        public readonly int $fetchedAtMillis,
    ) {
    }

    /**
     * 从 Bridge 返回的 JSON 数组构造结果对象。
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ExternalFetchException 当缺少必填的 markdown 字段时抛出
     */
    public static function fromArray(array $data): self
    {
        $markdown = isset($data['markdown']) ? (string) $data['markdown'] : '';
        if ($markdown === '') {
            throw new ExternalFetchException('Bridge response missing required field "markdown"');
        }

        $format = isset($data['format']) ? (string) $data['format'] : 'markdown';
        $node = isset($data['node']) ? (string) $data['node'] : '';

        // 兼容秒 / 毫秒两种时间戳：少于 10^12 视为秒级，自动放大到毫秒
        $rawTs = isset($data['fetched_at']) ? (int) $data['fetched_at'] : 0;
        if ($rawTs > 0 && $rawTs < 1_000_000_000_000) {
            $rawTs *= 1000;
        }

        return new self($markdown, $format, $node, $rawTs);
    }

    /**
     * 用于日志 / 持久化（例如写到 url_import_jobs.fetched_markdown 时附带元信息）。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'markdown' => $this->markdown,
            'format' => $this->format,
            'node' => $this->node,
            'fetched_at_millis' => $this->fetchedAtMillis,
        ];
    }
}
