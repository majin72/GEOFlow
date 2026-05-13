<footer class="ne-footer">
    <div class="ne-shell">
        <div class="ne-footer-inner flex flex-col items-center gap-2 text-center">
            <span>{{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName.'. All rights reserved.' }}</span>
            @include('site.partials.beian')
        </div>
    </div>
</footer>
