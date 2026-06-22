document.addEventListener('DOMContentLoaded', function () {
    const previewBtn = document.getElementById('preview-btn');
    if (!previewBtn) return;
    previewBtn.addEventListener('click', function (e) {
        e.preventDefault();
        const form = document.querySelector('form');
        if (!form) return;
        const data = new FormData(form);
        fetch(form.action || window.location.href, { method: 'POST', body: data, headers: { 'X-Preview': '1' } })
            .then(r => r.text()).then(html => {
                const w = window.open('', 'sow_preview', 'width=800,height=900');
                if (!w) return;
                const parsed = new DOMParser().parseFromString(html, 'text/html');
                parsed.querySelectorAll('script').forEach(script => script.remove());
                w.document.open();
                w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>SOW Preview</title></head><body></body></html>');
                w.document.close();
                Array.from(parsed.body.childNodes).forEach(node => {
                    w.document.body.appendChild(w.document.importNode(node, true));
                });
            });
    });
});