<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GeoMonitorProject;
use App\Services\GeoFlow\GeoMonitoring\Admin\GeoMonitorPromptSync;
use App\Support\AdminWeb;
use App\Support\GeoFlow\GeoMonitoring\GeoMonitorListParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GEO 监测项目后台 CRUD。
 */
class GeoMonitoringProjectController extends Controller
{
    /**
     * @param  GeoMonitorPromptSync  $promptSync  监测问题同步
     */
    public function __construct(
        private readonly GeoMonitorPromptSync $promptSync,
    ) {}

    /**
     * 新建项目表单。
     */
    public function create(): View
    {
        return view('admin.geo-monitoring.projects.form', [
            'pageTitle' => __('admin.geo_monitoring.project_create_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'projectId' => 0,
            'form' => $this->emptyProjectForm(),
        ]);
    }

    /**
     * 保存新项目。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedProjectPayload($request);
        $slug = trim((string) ($payload['slug'] ?? ''));

        if ($slug === '') {
            $slug = GeoMonitorListParser::uniqueProjectSlug($payload['name']);
        } elseif (GeoMonitorProject::query()->where('slug', $slug)->exists()) {
            return back()->withInput()->withErrors(__('admin.geo_monitoring.error.slug_exists'));
        }

        $project = GeoMonitorProject::query()->create([
            'name' => $payload['name'],
            'slug' => $slug,
            'brand_name' => $payload['brand_name'],
            'primary_domain' => $payload['primary_domain'],
            'competitor_domains' => $payload['competitor_domains'],
            'competitor_brands' => $payload['competitor_brands'],
            'product_keywords' => $payload['product_keywords'],
            'status' => $payload['status'],
            'notes' => $payload['notes'],
            'created_by_admin_id' => $request->user('admin')?->id,
        ]);

        $this->promptSync->syncFromTextarea($project, $payload['monitoring_questions']);

        return redirect()
            ->route('admin.geo-monitoring.project', ['projectId' => $project->id])
            ->with('message', __('admin.geo_monitoring.message.project_created'));
    }

    /**
     * 编辑项目表单。
     */
    public function edit(int $projectId): View|RedirectResponse
    {
        $project = GeoMonitorProject::query()->find($projectId);

        if ($project === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.project_not_found'));
        }

        return view('admin.geo-monitoring.projects.form', [
            'pageTitle' => __('admin.geo_monitoring.project_edit_title'),
            'activeMenu' => 'geo_monitoring',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'projectId' => $project->id,
            'form' => $this->projectFormFromModel($project),
        ]);
    }

    /**
     * 更新项目。
     */
    public function update(Request $request, int $projectId): RedirectResponse
    {
        $project = GeoMonitorProject::query()->find($projectId);

        if ($project === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.project_not_found'));
        }

        $payload = $this->validatedProjectPayload($request);
        $slug = trim((string) ($payload['slug'] ?? ''));

        if ($slug === '') {
            $slug = GeoMonitorListParser::uniqueProjectSlug($payload['name'], $project->id);
        } elseif (GeoMonitorProject::query()
            ->where('slug', $slug)
            ->where('id', '!=', $project->id)
            ->exists()) {
            return back()->withInput()->withErrors(__('admin.geo_monitoring.error.slug_exists'));
        }

        $project->update([
            'name' => $payload['name'],
            'slug' => $slug,
            'brand_name' => $payload['brand_name'],
            'primary_domain' => $payload['primary_domain'],
            'competitor_domains' => $payload['competitor_domains'],
            'competitor_brands' => $payload['competitor_brands'],
            'product_keywords' => $payload['product_keywords'],
            'status' => $payload['status'],
            'notes' => $payload['notes'],
        ]);

        $this->promptSync->syncFromTextarea($project, $payload['monitoring_questions']);

        return redirect()
            ->route('admin.geo-monitoring.project', ['projectId' => $project->id])
            ->with('message', __('admin.geo_monitoring.message.project_updated'));
    }

    /**
     * 停用项目（软停用，保留历史数据）。
     */
    public function deactivate(int $projectId): RedirectResponse
    {
        $project = GeoMonitorProject::query()->find($projectId);

        if ($project === null) {
            return redirect()
                ->route('admin.geo-monitoring.index')
                ->withErrors(__('admin.geo_monitoring.message.project_not_found'));
        }

        $project->update(['status' => 'inactive']);

        return redirect()
            ->route('admin.geo-monitoring.index')
            ->with('message', __('admin.geo_monitoring.message.project_deactivated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProjectForm(): array
    {
        return [
            'name' => '',
            'slug' => '',
            'brand_name' => '',
            'primary_domain' => '',
            'competitor_domains' => '',
            'competitor_brands' => '',
            'product_keywords' => '',
            'monitoring_questions' => '',
            'status' => 'active',
            'notes' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectFormFromModel(GeoMonitorProject $project): array
    {
        $project->load(['prompts' => fn ($query) => $query->where('is_enabled', true)->orderBy('code')]);

        return [
            'name' => $project->name,
            'slug' => $project->slug,
            'brand_name' => $project->brand_name,
            'primary_domain' => $project->primary_domain,
            'competitor_domains' => GeoMonitorListParser::formatForTextarea($project->competitor_domains),
            'competitor_brands' => GeoMonitorListParser::formatForTextarea($project->competitor_brands),
            'product_keywords' => GeoMonitorListParser::formatForTextarea($project->product_keywords),
            'monitoring_questions' => $project->prompts
                ->pluck('prompt_text')
                ->implode("\n"),
            'status' => $project->status,
            'notes' => (string) ($project->notes ?? ''),
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     slug: string,
     *     brand_name: string,
     *     primary_domain: string,
     *     competitor_domains: list<string>,
     *     competitor_brands: list<string>,
     *     product_keywords: list<string>,
     *     monitoring_questions: string|null,
     *     status: string,
     *     notes: string|null
     * }
     */
    private function validatedProjectPayload(Request $request): array
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'brand_name' => ['nullable', 'string', 'max:160'],
            'primary_domain' => ['nullable', 'string', 'max:255'],
            'competitor_domains' => ['nullable', 'string'],
            'competitor_brands' => ['nullable', 'string'],
            'product_keywords' => ['nullable', 'string'],
            'monitoring_questions' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
            'notes' => ['nullable', 'string'],
        ], [
            'name.required' => __('admin.geo_monitoring.error.name_required'),
            'slug.regex' => __('admin.geo_monitoring.error.slug_format'),
        ]);

        return [
            'name' => trim((string) $payload['name']),
            'slug' => trim((string) ($payload['slug'] ?? '')),
            'brand_name' => trim((string) ($payload['brand_name'] ?? '')),
            'primary_domain' => trim((string) ($payload['primary_domain'] ?? '')),
            'competitor_domains' => GeoMonitorListParser::parse($payload['competitor_domains'] ?? null),
            'competitor_brands' => GeoMonitorListParser::parse($payload['competitor_brands'] ?? null),
            'product_keywords' => GeoMonitorListParser::parse($payload['product_keywords'] ?? null),
            'monitoring_questions' => trim((string) ($payload['monitoring_questions'] ?? '')) ?: null,
            'status' => (string) $payload['status'],
            'notes' => ($notes = trim((string) ($payload['notes'] ?? ''))) !== '' ? $notes : null,
        ];
    }
}
