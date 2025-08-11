<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Text to 16:9 PNG</title>
    <script src="https://cdn.tailwindcss.com"></script>
  </head>
  <body class="bg-gray-100 text-gray-900 min-h-screen">
    <div class="max-w-5xl mx-auto px-4 py-8">
      <h1 class="text-2xl font-semibold mb-6">Text → 16:9 PNG generator</h1>

      <form id="generatorForm" class="bg-white rounded-lg shadow p-6 space-y-4">
        <div>
          <label for="mainText" class="block text-sm font-medium mb-1">Text (will be split into 150-character chunks)</label>
          <textarea id="mainText" class="w-full rounded border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 px-3 py-2" rows="4" placeholder="Enter your text..."></textarea>
          <p id="charInfo" class="text-xs text-gray-500 mt-1"></p>
        </div>
        <div>
          <label for="sourceText" class="block text-sm font-medium mb-1">Source (optional, will appear bottom-right in braces)</label>
          <input id="sourceText" type="text" class="w-full rounded border-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 px-3 py-2" placeholder="e.g., Example Report" />
        </div>
        <div class="flex items-center gap-3">
          <button type="submit" class="inline-flex items-center justify-center bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded">Generate</button>
          <span id="resultMeta" class="text-sm text-gray-600"></span>
        </div>
      </form>

      <div id="results" class="mt-8 grid gap-6 md:grid-cols-2"></div>
    </div>

    <script>
      const form = document.getElementById('generatorForm');
      const mainTextEl = document.getElementById('mainText');
      const sourceTextEl = document.getElementById('sourceText');
      const resultsEl = document.getElementById('results');
      const charInfoEl = document.getElementById('charInfo');
      const resultMetaEl = document.getElementById('resultMeta');

      function chunkString(str, size) {
        const chunks = [];
        for (let i = 0; i < str.length; i += size) {
          chunks.push(str.slice(i, i + size));
        }
        return chunks;
      }

      function updateCharInfo() {
        const len = (mainTextEl.value || '').length;
        const chunks = Math.ceil(len / 150) || 0;
        charInfoEl.textContent = len > 0
          ? `${len} characters total → ${chunks} image${chunks === 1 ? '' : 's'}`
          : '';
      }

      mainTextEl.addEventListener('input', updateCharInfo);

      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const rawText = (mainTextEl.value || '').trim();
        const source = (sourceTextEl.value || '').trim();
        resultsEl.innerHTML = '';
        resultMetaEl.textContent = '';

        if (!rawText) {
          resultMetaEl.textContent = 'Please enter text.';
          return;
        }

        const chunks = chunkString(rawText, 150);
        resultMetaEl.textContent = `Generated ${chunks.length} image${chunks.length === 1 ? '' : 's'}`;

        chunks.forEach((chunk, idx) => {
          const params = new URLSearchParams();
          params.set('text', chunk);
          if (source) params.set('source', source);

          const cacheBust = Date.now() + '-' + idx;

          const imgUrl = `generate.php?${params.toString()}&cb=${cacheBust}`;
          const dlUrl = `generate.php?${params.toString()}&download=1`;

          const card = document.createElement('div');
          card.className = 'bg-white rounded-lg shadow p-4 flex flex-col gap-3';

          const imgWrap = document.createElement('div');
          imgWrap.className = 'w-full aspect-video bg-gray-200 overflow-hidden rounded';

          const img = document.createElement('img');
          img.src = imgUrl;
          img.alt = `Generated image ${idx + 1}`;
          img.className = 'w-full h-full object-cover';

          img.addEventListener('error', () => {
            imgWrap.className = 'w-full aspect-video bg-red-100 overflow-hidden rounded flex items-center justify-center';
            imgWrap.innerHTML = '';
            const err = document.createElement('div');
            err.className = 'text-sm text-red-700 px-3 text-center';
            err.textContent = 'Failed to load image. Open the Debug report to investigate.';
            imgWrap.appendChild(err);
          });

          imgWrap.appendChild(img);

          const meta = document.createElement('div');
          meta.className = 'flex items-center justify-between';

          const caption = document.createElement('div');
          caption.className = 'text-sm text-gray-600';
          caption.textContent = `Image ${idx + 1}${chunks.length > 1 ? ` of ${chunks.length}` : ''}`;

          const actions = document.createElement('div');
          const a = document.createElement('a');
          a.href = dlUrl;
          a.className = 'inline-flex items-center justify-center bg-gray-900 hover:bg-black text-white text-sm font-medium px-3 py-2 rounded';
          a.textContent = 'Download PNG';
          a.setAttribute('download', `image-${idx + 1}.png`);
          actions.appendChild(a);

          const debugLink = document.createElement('a');
          debugLink.href = imgUrl + '&debug=1';
          debugLink.target = '_blank';
          debugLink.rel = 'noopener noreferrer';
          debugLink.className = 'ml-3 inline-flex items-center justify-center bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-medium px-3 py-2 rounded';
          debugLink.textContent = 'Debug report';
          actions.appendChild(debugLink);

          meta.appendChild(caption);
          meta.appendChild(actions);

          card.appendChild(imgWrap);
          card.appendChild(meta);
          resultsEl.appendChild(card);
        });
      });

      updateCharInfo();
    </script>
  </body>
</html>