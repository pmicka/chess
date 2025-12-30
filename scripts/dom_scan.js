#!/usr/bin/env node

/**
 * Lightweight HTML scanner for obvious chess tokens.
 *
 * Usage:
 *   node scripts/dom_scan.js <url-or-filepath>
 */

const fs = require('fs');
const path = require('path');
const { URL } = require('url');

async function readSource(target) {
  try {
    const maybeUrl = new URL(target);
    if (maybeUrl.protocol === 'http:' || maybeUrl.protocol === 'https:') {
      const res = await fetch(maybeUrl, { redirect: 'follow' });
      if (!res.ok) {
        throw new Error(`HTTP ${res.status} ${res.statusText}`);
      }
      return await res.text();
    }
  } catch (err) {
    // Not a URL, fall through to file read
  }

  const filePath = path.resolve(process.cwd(), target);
  return fs.readFileSync(filePath, 'utf8');
}

function scanHtml(html) {
  const squareRegex = /\b([a-h][1-8])\b/gi;
  const notationRegex = /\b(fen|pgn|san)\b/gi;

  const squareMatches = [...html.matchAll(squareRegex)].map((m) => m[0]);
  const notationMatches = [...html.matchAll(notationRegex)].map((m) => m[0]);

  return {
    squareCount: squareMatches.length,
    notationCount: notationMatches.length,
    sampleSquares: squareMatches.slice(0, 10),
    sampleNotation: notationMatches.slice(0, 10),
  };
}

async function main() {
  const target = process.argv[2];
  if (!target) {
    console.error('Usage: node scripts/dom_scan.js <url-or-filepath>');
    process.exit(1);
  }

  try {
    const html = await readSource(target);
    const result = scanHtml(html);
    console.log(JSON.stringify(result, null, 2));
  } catch (err) {
    console.error('Scan failed:', err && err.message ? err.message : err);
    process.exit(1);
  }
}

main();
