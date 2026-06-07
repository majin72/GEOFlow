<div class="rounded-lg bg-white p-6 shadow">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-lg font-medium text-gray-900">{{ __('admin.geo_monitoring.report_title') }}</h2>
            <p class="mt-1 text-sm text-gray-600">{{ $runReport['conclusion'] }}</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-center">
                <div class="text-xs font-medium uppercase tracking-wide text-blue-600">{{ __('admin.geo_monitoring.report_geo_score') }}</div>
                <div class="mt-1 text-3xl font-bold text-blue-900">{{ number_format($runReport['geo_score'], 1) }}</div>
            </div>
            <div class="text-xs text-gray-400">{{ $runReport['score_version'] }}</div>
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.geo_monitoring.report_brand_mention_rate') }}</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $runReport['brand_mention_rate'] }}%</div>
            <div class="mt-1 text-xs text-gray-500">{{ $runReport['brand_mentions'] }}/{{ $runReport['eligible_observations'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.geo_monitoring.report_own_citation_rate') }}</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $runReport['own_citation_rate'] }}%</div>
            <div class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.report_own_citations') }}: {{ $runReport['own_citations'] }}</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.geo_monitoring.report_citation_coverage') }}</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $runReport['citation_coverage_rate'] }}%</div>
            <div class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.report_platform_coverage') }}: {{ $runReport['platform_coverage_index'] }}%</div>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ __('admin.geo_monitoring.report_competitor_citations') }}</div>
            <div class="mt-2 text-2xl font-semibold text-gray-900">{{ $runReport['competitor_citations'] }}</div>
            <div class="mt-1 text-xs text-gray-500">{{ __('admin.geo_monitoring.report_competitor_mentions') }}: {{ $runReport['competitor_mentions'] }}</div>
        </div>
    </div>

    @if (! empty($runReport['keyword_hits']))
        <div class="mt-4 flex flex-wrap gap-1">
            @foreach ($runReport['keyword_hits'] as $keyword)
                <span class="inline-flex rounded-full bg-blue-50 px-2 py-1 text-xs text-blue-700">{{ $keyword }}</span>
            @endforeach
        </div>
    @endif
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-lg bg-white shadow">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="text-base font-medium text-gray-900">{{ __('admin.geo_monitoring.report_platform_breakdown') }}</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_platform') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.report_geo_score') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.report_brand_mention_rate') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.report_own_citation_rate') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($runReport['platform_breakdown'] as $row)
                        <tr>
                            <td class="px-4 py-3 text-gray-900">{{ $row['platform_label'] }}</td>
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $row['geo_score'] }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $row['brand_mention_rate'] }}%</td>
                            <td class="px-4 py-3 text-gray-600">{{ $row['own_citation_rate'] }}%</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-6 text-gray-400">{{ __('admin.geo_monitoring.report_none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="rounded-lg bg-white shadow">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="text-base font-medium text-gray-900">{{ __('admin.geo_monitoring.report_competitor_comparison') }}</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.report_entity') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.report_mention_observations') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-gray-500">{{ __('admin.geo_monitoring.field_citations') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($runReport['competitor_comparison'] as $row)
                        <tr class="{{ $row['type'] === 'own' ? 'bg-emerald-50/50' : '' }}">
                            <td class="px-4 py-3 text-gray-900">
                                {{ $row['name'] }}
                                @if ($row['type'] === 'own')
                                    <span class="ml-1 text-xs text-emerald-700">({{ __('admin.geo_monitoring.report_own_brand') }})</span>
                                @elseif ($row['type'] === 'competitor_domain')
                                    <span class="ml-1 text-xs text-gray-400">({{ __('admin.geo_monitoring.report_competitor_domain') }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ $row['mention_observations'] }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $row['citation_count'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-gray-400">{{ __('admin.geo_monitoring.report_none') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-lg bg-white shadow">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="text-base font-medium text-gray-900">{{ __('admin.geo_monitoring.report_prompt_breakdown') }}</h3>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse ($runReport['prompt_breakdown'] as $row)
                <div class="px-6 py-4">
                    <div class="line-clamp-2 text-sm text-gray-900">{{ $row['prompt_text'] }}</div>
                    <div class="mt-2 flex gap-4 text-xs text-gray-500">
                        <span>{{ __('admin.geo_monitoring.report_brand_mention_rate') }}: {{ $row['brand_mention_rate'] }}%</span>
                        <span>{{ __('admin.geo_monitoring.report_own_citation_rate') }}: {{ $row['own_citation_rate'] }}%</span>
                    </div>
                </div>
            @empty
                <div class="px-6 py-6 text-sm text-gray-400">{{ __('admin.geo_monitoring.report_none') }}</div>
            @endforelse
        </div>
    </div>

    <div class="rounded-lg bg-white shadow">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="text-base font-medium text-gray-900">{{ __('admin.geo_monitoring.report_top_sources') }}</h3>
        </div>
        <div class="divide-y divide-gray-100">
            @forelse ($runReport['top_sources'] as $row)
                <div class="flex items-center justify-between px-6 py-3 text-sm">
                    <div>
                        <span class="font-medium text-gray-900">{{ $row['domain'] }}</span>
                        @if ($row['is_own'])
                            <span class="ml-2 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">{{ __('admin.geo_monitoring.report_own_domain') }}</span>
                        @elseif ($row['is_competitor'])
                            <span class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">{{ __('admin.geo_monitoring.report_competitor_domain') }}</span>
                        @endif
                    </div>
                    <span class="text-gray-500">{{ $row['count'] }}</span>
                </div>
            @empty
                <div class="px-6 py-6 text-sm text-gray-400">{{ __('admin.geo_monitoring.report_none') }}</div>
            @endforelse
        </div>
    </div>
</div>

@if (! empty($runReport['failure_distribution']))
    <div class="rounded-lg bg-white shadow">
        <div class="border-b border-gray-200 px-6 py-4">
            <h3 class="text-base font-medium text-gray-900">{{ __('admin.geo_monitoring.report_failure_distribution') }}</h3>
        </div>
        <div class="flex flex-wrap gap-2 px-6 py-4">
            @foreach ($runReport['failure_distribution'] as $row)
                <span class="inline-flex items-center gap-2 rounded-full border border-red-200 bg-red-50 px-3 py-1 text-sm text-red-800">
                    <span class="font-medium">{{ $row['status'] }}</span>
                    <span class="text-red-600">{{ $row['count'] }}</span>
                </span>
            @endforeach
        </div>
    </div>
@endif
