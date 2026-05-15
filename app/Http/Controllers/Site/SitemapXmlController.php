<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Site\SiteStaticSitemapBuilder;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * 从与 Docker 共用的 storage 卷读取已生成的静态 XML；后续拆分为多文件时仅需约定路径与生成命令，无需再改 Nginx。
 */
final class SitemapXmlController extends Controller
{
    /**
     * 主入口：根路径 sitemap.xml（通常为索引或整站单文件）。
     */
    public function main(ResponseFactory $responseFactory): BinaryFileResponse|Response
    {
        return $this->respondFileIfExists(
            $responseFactory,
            SiteStaticSitemapBuilder::defaultOutputPath()
        );
    }

    /**
     * 分片：/sitemaps/{segment}.xml → storage/app/public/sitemaps/{segment}.xml。
     */
    public function chunk(ResponseFactory $responseFactory, string $segment): BinaryFileResponse|Response
    {
        if (! SiteStaticSitemapBuilder::isValidChunkSegment($segment)) {
            abort(404);
        }

        return $this->respondFileIfExists(
            $responseFactory,
            SiteStaticSitemapBuilder::chunkOutputPath($segment)
        );
    }

    /**
     * 若文件存在则以内联方式返回 XML，否则 404。
     *
     * @param  non-empty-string  $absolutePath
     */
    private function respondFileIfExists(ResponseFactory $responseFactory, string $absolutePath): BinaryFileResponse|Response
    {
        if (! is_file($absolutePath)) {
            abort(404);
        }

        return $responseFactory->file($absolutePath, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }
}
