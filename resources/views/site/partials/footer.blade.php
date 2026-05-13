<footer class="bg-white border-t border-gray-100 mt-16">
    <div class="site-container px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex flex-col items-center gap-2 text-center">
            <p class="text-gray-500 text-sm">{{ $footerCopyright !== '' ? $footerCopyright : '© '.date('Y').' '.$siteName }}</p>
            @include('site.partials.beian')
        </div>
    </div>
</footer>
