import { mountPgnReview } from '../src/pgn-review.js';

const DEFAULT_ASSETS_BASE_URL = '/assets/vendor/pgn-review/';

function resolveAssetsBaseUrl() {
  const fromWindow = typeof window !== 'undefined' ? window.PGN_REVIEW_ASSETS_BASE_URL : null;
  const fromDataset = document?.documentElement?.dataset?.pgnReviewAssetsBaseUrl;
  const raw = fromWindow || fromDataset || DEFAULT_ASSETS_BASE_URL;
  return raw.endsWith('/') ? raw : `${raw}/`;
}

function findPgnBlocks() {
  return Array.from(document.querySelectorAll('pre > code.language-pgn, pre > code.pgn'));
}

function mountForBlock(codeEl) {
  const pre = codeEl.closest('pre');
  if (!pre) return;

  let container = pre.nextElementSibling;
  if (!container || !container.classList.contains('pgn-review')) {
    container = document.createElement('div');
    container.className = 'pgn-review';
    pre.insertAdjacentElement('afterend', container);
  }

  if (container.dataset.mounted === '1') return;
  container.dataset.mounted = '1';

  const pgn = codeEl.textContent || '';
  mountPgnReview(container, {
    pgn,
    showExplorer: true,
    showNotation: true,
    allowStorage: true,
    assetsBaseUrl: resolveAssetsBaseUrl(),
  });
}

function init() {
  const blocks = findPgnBlocks();
  blocks.forEach((codeEl) => mountForBlock(codeEl));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
