<?php

namespace App\Providers;

use App\Models\Admin;
use App\Services\Admin\AdminUpdateMetadataService;
use App\Services\Admin\AdminWelcomeModalService;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\ArticleSearch\ArticleSearchConfig;
use App\Services\GeoFlow\ArticleSearch\TavilyArticleSearchService;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchConfig;
use App\Services\GeoFlow\ExternalFetch\ExternalFetchService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorConfig;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorMaintenanceService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorProbePersister;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceHealthService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorResourceScheduler;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRunService;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorRuntimeConfig;
use App\Services\GeoFlow\GeoMonitoring\GeoMonitorSidecarAccountsExporter;
use App\Services\GeoFlow\GeoMonitoring\ScraplingBridgeClient;
use App\Services\GeoFlow\HorizonMetricsAdapter;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Support\GeoFlow\OutboundHttpProxy;
use App\View\Composers\SiteLayoutComposer;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(JobQueueService::class);
        $this->app->singleton(HorizonMetricsAdapter::class);
        $this->app->singleton(TaskMonitoringQueryService::class);
        $this->app->singleton(TaskLifecycleService::class);
        $this->app->singleton(ArticleGeoFlowService::class);
        $this->app->bind(ExternalFetchService::class, function ($app): ExternalFetchService {
            return new ExternalFetchService(
                ExternalFetchConfig::fromSettings(),
                $app->make(HttpFactory::class),
            );
        });
        $this->app->bind(TavilyArticleSearchService::class, function ($app): TavilyArticleSearchService {
            return new TavilyArticleSearchService(
                ArticleSearchConfig::fromSettings(),
                $app->make(HttpFactory::class),
                $app->make(CacheRepository::class),
            );
        });
        $this->app->singleton(GeoMonitorConfig::class, fn (): GeoMonitorConfig => GeoMonitorConfig::fromConfig());
        $this->app->singleton(ScraplingBridgeClient::class, function ($app): ScraplingBridgeClient {
            return new ScraplingBridgeClient(
                $app->make(GeoMonitorConfig::class),
                $app->make(HttpFactory::class),
            );
        });
        $this->app->singleton(GeoMonitorProbePersister::class);
        $this->app->singleton(GeoMonitorEvidenceRepairService::class);
        $this->app->singleton(GeoMonitorRuntimeConfig::class, fn (): GeoMonitorRuntimeConfig => GeoMonitorRuntimeConfig::fromConfig());
        $this->app->singleton(GeoMonitorResourceScheduler::class, fn (): GeoMonitorResourceScheduler => GeoMonitorResourceScheduler::fromConfig());
        $this->app->singleton(GeoMonitorResourceHealthService::class, fn (): GeoMonitorResourceHealthService => GeoMonitorResourceHealthService::fromConfig());
        $this->app->singleton(GeoMonitorMaintenanceService::class, fn (): GeoMonitorMaintenanceService => GeoMonitorMaintenanceService::fromConfig());
        $this->app->singleton(GeoMonitorSidecarAccountsExporter::class);
        $this->app->singleton(GeoMonitorRunService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Http::globalMiddleware(OutboundHttpProxy::middleware());

        $this->ensureGeoMonitorEvidenceDirectory();

        View::composer(['site.layout', 'theme.*.layout'], SiteLayoutComposer::class);

        View::composer('admin.layouts.app', function ($view): void {
            $admin = auth('admin')->user();
            $view->with(
                'adminWelcomeModalPayload',
                $admin instanceof Admin ? app(AdminWelcomeModalService::class)->buildModalPayload($admin) : null
            );
            $view->with(
                'adminUpdateNotificationPayload',
                $admin instanceof Admin ? app(AdminUpdateMetadataService::class)->buildNotificationPayload() : null
            );
        });
    }

    /**
     * GEO 监测启用时确保证据目录存在（storage 挂载点，与 sidecar 共用）。
     */
    private function ensureGeoMonitorEvidenceDirectory(): void
    {
        if (! (bool) config('geoflow.geo_monitor.enabled', false)) {
            return;
        }

        $evidenceRoot = trim((string) config('geoflow.geo_monitor.evidence_root', ''));

        if ($evidenceRoot === '' || is_dir($evidenceRoot)) {
            return;
        }

        @mkdir($evidenceRoot, 0775, true);
    }
}
