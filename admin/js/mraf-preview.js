(function () {
    const container = document.getElementById('mraf-preview');
    if (!container) return;

    const fileInput = document.getElementById('mraf_file');
    const keepCheckbox = document.querySelector('input[name="mraf_keep_basename"]');
    const nameEl = container.querySelector('[data-preview="name"]');
    const urlEl = container.querySelector('[data-preview="url"]');
    const sizeEl = container.querySelector('[data-preview="size"]');
    const dimsEl = container.querySelector('[data-preview="dimensions"]');
    const statusEl = container.querySelector('.mraf-preview__status');
    if (!fileInput || !nameEl || !urlEl || !sizeEl || !dimsEl || !statusEl) return;

    const dirUrl = container.dataset.dirUrl || '';
    const oldBase = container.dataset.oldBase || 'file';

    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return (bytes / Math.pow(1024, index)).toFixed(index ? 1 : 0) + ' ' + units[index];
    }

    function sanitizeBase(str) {
        return str
            .toLowerCase()
            .replace(/\.[^.]+$/, '')
            .replace(/[^a-z0-9-_]+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '') || oldBase;
    }

    function predictName(file) {
        const keep = keepCheckbox ? keepCheckbox.checked : true;
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const base = keep ? oldBase : sanitizeBase(file.name);
        return ext ? base + '.' + ext : base;
    }

    function setStatus(message) {
        statusEl.textContent = message || '';
    }

    function updatePreview() {
        const file = fileInput.files && fileInput.files[0];
        if (!file) {
            container.classList.remove('has-preview');
            setStatus(container.dataset.emptyText || '');
            nameEl.textContent = '�';
            urlEl.textContent = '�';
            urlEl.removeAttribute('href');
            sizeEl.textContent = '�';
            dimsEl.textContent = 'by';
            return;
        }

        const predictedName = predictName(file);
        const predictedUrl = dirUrl + predictedName;

        nameEl.textContent = predictedName;
        urlEl.textContent = predictedUrl;
        urlEl.href = predictedUrl;
        sizeEl.textContent = formatBytes(file.size);
        dimsEl.textContent = '�';
        container.classList.add('has-preview');
        setStatus('');

        const objectUrl = URL.createObjectURL(file);
        const img = new Image();
        img.onload = function () {
            dimsEl.textContent = img.width + ' � ' + img.height;
            URL.revokeObjectURL(objectUrl);
        };
        img.onerror = function () {
            dimsEl.textContent = '�';
            URL.revokeObjectURL(objectUrl);
        };
        img.src = objectUrl;
    }

    fileInput.addEventListener('change', updatePreview);
    if (keepCheckbox) {
        keepCheckbox.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length) {
                updatePreview();
            }
        });
    }

    container.dataset.emptyText = container.dataset.emptyText || statusEl.textContent || '';
})();
