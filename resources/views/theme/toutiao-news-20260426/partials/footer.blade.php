<footer class="tt-footer">
    <div class="tt-shell">
        <div class="tt-footer-inner flex flex-col items-center gap-2 text-center">
            <span>{{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName.'. All rights reserved.' }}</span>
            @include('site.partials.beian')
        </div>
    </div>
</footer>
