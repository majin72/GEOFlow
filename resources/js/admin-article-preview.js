/**
 * 后台文章 Markdown 预览：marked 渲染后经 DOMPurify 净化再写入 DOM，防止存储型 XSS。
 */
import DOMPurify from 'dompurify';
import { marked } from 'marked';

marked.setOptions({
    gfm: true,
});

let previewVisible = false;

/**
 * 将正文 textarea 内容渲染到预览面板（已净化 HTML）。
 */
function renderPreview() {
    const source = document.getElementById('content-textarea');
    const target = document.getElementById('content-preview');
    if (!source || !target) {
        return;
    }

    const rawHtml = marked.parse(source.value || '', { async: false });
    target.innerHTML = DOMPurify.sanitize(rawHtml, { USE_PROFILES: { html: true } });
}

/**
 * 切换编辑/预览视图。
 */
function togglePreview() {
    const textarea = document.getElementById('content-textarea');
    const panel = document.getElementById('content-preview-panel');
    const toggleText = document.getElementById('preview-toggle-text');

    if (!textarea || !panel || !toggleText) {
        return;
    }

    previewVisible = !previewVisible;
    if (previewVisible) {
        renderPreview();
        textarea.classList.add('hidden');
        panel.classList.remove('hidden');
        panel.setAttribute('aria-hidden', 'false');
        toggleText.textContent = toggleText.dataset.labelHide || toggleText.textContent;
    } else {
        textarea.classList.remove('hidden');
        panel.classList.add('hidden');
        panel.setAttribute('aria-hidden', 'true');
        toggleText.textContent = toggleText.dataset.labelShow || toggleText.textContent;
    }
}

window.renderArticlePreview = renderPreview;
window.toggleArticlePreview = togglePreview;
