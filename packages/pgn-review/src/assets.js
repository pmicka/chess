export const DEFAULT_ASSETS_BASE_URL = '/assets/vendor/pgn-review/';

export function resolveAssetsBaseUrl(input) {
  const raw = typeof input === 'string' && input.trim() ? input.trim() : DEFAULT_ASSETS_BASE_URL;
  return raw.endsWith('/') ? raw : `${raw}/`;
}

export function getPieceBaseUrl(assetsBaseUrl) {
  return `${resolveAssetsBaseUrl(assetsBaseUrl)}pieces/lichess/`;
}

export function getPieceUrl(color, type, assetsBaseUrl) {
  const base = getPieceBaseUrl(assetsBaseUrl);
  const colorPrefix = String(color).toLowerCase() === 'b' ? 'b' : 'w';
  const pieceCode = String(type || '').toUpperCase();
  return `${base}${colorPrefix}${pieceCode}.svg`;
}

export function getEcoLiteUrl(assetsBaseUrl) {
  return `${resolveAssetsBaseUrl(assetsBaseUrl)}data/eco_lite.json`;
}
