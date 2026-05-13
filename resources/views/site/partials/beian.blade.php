{{--
    备案号展示组件（前台页脚专用）。
    依赖 SiteLayoutComposer 注入的变量：siteIcpBeian / siteIcpBeianUrl / sitePoliceBeian / sitePoliceBeianUrl。
    任意一个备案号未填写时不渲染该项；两者都未填写时整体不渲染，避免页脚出现空 inline 占位。
--}}
@php
    $icpBeian = $siteIcpBeian ?? '';
    $icpBeianUrl = $siteIcpBeianUrl ?? '';
    $policeBeian = $sitePoliceBeian ?? '';
    $policeBeianUrl = $sitePoliceBeianUrl ?? '';
@endphp
@if($icpBeian !== '' || $policeBeian !== '')
    <span class="inline-flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-gray-500">
        @if($icpBeian !== '')
            <a href="{{ $icpBeianUrl }}" target="_blank" rel="nofollow noopener" class="hover:text-gray-700">{{ $icpBeian }}</a>
        @endif
        @if($policeBeian !== '')
            @if($policeBeianUrl !== '')
                <a href="{{ $policeBeianUrl }}" target="_blank" rel="nofollow noopener" class="inline-flex items-center gap-1 hover:text-gray-700">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true" class="text-[#1f6fdc]"><path d="M12 2.5 4.5 5.4v6.1c0 4.9 3.2 9 7.5 10 4.3-1 7.5-5.1 7.5-10V5.4L12 2.5Zm-1.1 13.2-3.2-3.2 1.3-1.3 1.9 1.9 4.2-4.2 1.3 1.3-5.5 5.5Z"/></svg>
                    {{ $policeBeian }}
                </a>
            @else
                <span class="inline-flex items-center gap-1">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true" class="text-[#1f6fdc]"><path d="M12 2.5 4.5 5.4v6.1c0 4.9 3.2 9 7.5 10 4.3-1 7.5-5.1 7.5-10V5.4L12 2.5Zm-1.1 13.2-3.2-3.2 1.3-1.3 1.9 1.9 4.2-4.2 1.3 1.3-5.5 5.5Z"/></svg>
                    {{ $policeBeian }}
                </span>
            @endif
        @endif
    </span>
@endif
