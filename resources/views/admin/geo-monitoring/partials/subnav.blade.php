<nav class="flex flex-wrap gap-3 text-sm">
    <a href="{{ route('admin.geo-monitoring.index') }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.projects_title') }}</a>
    <span class="text-gray-300">|</span>
    <a href="{{ route('admin.geo-monitoring.accounts.index') }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.nav_accounts') }}</a>
    <span class="text-gray-300">|</span>
    <a href="{{ route('admin.geo-monitoring.proxies.index') }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.nav_proxies') }}</a>
    <span class="text-gray-300">|</span>
    <a href="{{ route('admin.geo-monitoring.profiles.index') }}" class="text-blue-600 hover:text-blue-800">{{ __('admin.geo_monitoring.nav_profiles') }}</a>
</nav>
