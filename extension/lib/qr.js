// Tiny QR generator (ECC L, byte mode) — pure JS, no deps, returns SVG string.
//
// Subset of Project Nayuki's QR-Code-generator simplified for the byte-mode
// payloads we need (kaspa: URIs are <100 chars, comfortably fits version 5–10).
// Output is a self-contained SVG string which can be set as innerHTML.
//
// Public API:  generateQrSvg(text, sizePx = 200) → string
//
// Algorithm constants per ISO/IEC 18004 (QR Code 2005) — adapted for ECC level L only.

// ─── error correction polynomials and capacity tables ────────────────────────
// For each version (1..10), capacity (in bytes) at ECC level L
const CAPACITIES_L = [17, 32, 53, 78, 106, 134, 154, 192, 230, 271];
// Number of error correction codewords per block, ECC level L, by version 1..10
const ECC_CODEWORDS_L = [7, 10, 15, 20, 26, 18, 20, 24, 30, 18];
// Number of blocks at ECC L, by version 1..10
const NUM_BLOCKS_L = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

// ─── Galois field GF(256) for Reed-Solomon ───────────────────────────────────
const GF_EXP = new Array(512);
const GF_LOG = new Array(256);
(function initGF() {
  let x = 1;
  for (let i = 0; i < 255; i++) {
    GF_EXP[i] = x;
    GF_LOG[x] = i;
    x <<= 1;
    if (x & 0x100) x ^= 0x11d;
  }
  for (let i = 255; i < 512; i++) GF_EXP[i] = GF_EXP[i - 255];
})();
function gfMul(a, b) {
  if (a === 0 || b === 0) return 0;
  return GF_EXP[GF_LOG[a] + GF_LOG[b]];
}

// Compute generator polynomial of degree `n`
function rsGenerator(n) {
  let g = [1];
  for (let i = 0; i < n; i++) {
    const next = new Array(g.length + 1).fill(0);
    for (let j = 0; j < g.length; j++) {
      next[j] ^= g[j];
      next[j + 1] ^= gfMul(g[j], GF_EXP[i]);
    }
    g = next;
  }
  return g;
}
function rsRemainder(data, gen) {
  const out = new Array(gen.length - 1).fill(0);
  for (const b of data) {
    const factor = b ^ out.shift();
    out.push(0);
    for (let i = 0; i < gen.length - 1; i++) {
      out[i] ^= gfMul(gen[i + 1], factor);
    }
  }
  return out;
}

// ─── encode bytes (mode = 0100 byte mode) ───────────────────────────────────
function encodeData(text, version) {
  const bytes = new TextEncoder().encode(text);
  const totalBits = capacityBits(version);
  const bits = [];
  // Mode indicator: 0100 (byte)
  pushBits(bits, 0b0100, 4);
  // Character count: 8 bits (v1-9) or 16 bits (v10-26)
  const ccLen = version <= 9 ? 8 : 16;
  pushBits(bits, bytes.length, ccLen);
  for (const b of bytes) pushBits(bits, b, 8);
  // Terminator (up to 4 zero bits, but not past totalBits)
  for (let i = 0; i < 4 && bits.length < totalBits; i++) bits.push(0);
  // Pad to byte
  while (bits.length % 8 !== 0) bits.push(0);
  // Pad bytes 0xEC, 0x11 alternating
  const padBytes = [0xEC, 0x11];
  let padIdx = 0;
  while (bits.length < totalBits) {
    pushBits(bits, padBytes[padIdx % 2], 8);
    padIdx++;
  }
  // Pack bits → bytes
  const out = new Array(totalBits / 8);
  for (let i = 0; i < out.length; i++) {
    let v = 0;
    for (let j = 0; j < 8; j++) v = (v << 1) | bits[i * 8 + j];
    out[i] = v;
  }
  return out;
}
function pushBits(arr, value, n) {
  for (let i = n - 1; i >= 0; i--) arr.push((value >> i) & 1);
}
function capacityBits(version) {
  return CAPACITIES_L[version - 1] * 8;
}

// ─── interleave blocks (ECC) ────────────────────────────────────────────────
function makeFinalCodewords(data, version) {
  const numBlocks = NUM_BLOCKS_L[version - 1];
  const eccPer = ECC_CODEWORDS_L[version - 1];
  const totalData = CAPACITIES_L[version - 1];
  const blockSize = Math.floor(totalData / numBlocks);
  const numLargeBlocks = totalData % numBlocks;
  // Split into blocks
  const blocks = [];
  let pos = 0;
  for (let i = 0; i < numBlocks; i++) {
    const sz = blockSize + (i < (numBlocks - numLargeBlocks) ? 0 : 1);
    blocks.push(data.slice(pos, pos + sz));
    pos += sz;
  }
  // ECC for each block
  const gen = rsGenerator(eccPer);
  const eccBlocks = blocks.map(b => rsRemainder(b, gen));
  // Interleave
  const out = [];
  const maxData = blocks.reduce((m, b) => Math.max(m, b.length), 0);
  for (let i = 0; i < maxData; i++) {
    for (const b of blocks) if (i < b.length) out.push(b[i]);
  }
  for (let i = 0; i < eccPer; i++) {
    for (const b of eccBlocks) out.push(b[i]);
  }
  return out;
}

// ─── module placement ───────────────────────────────────────────────────────
function newMatrix(size) {
  const m = new Array(size);
  for (let i = 0; i < size; i++) m[i] = new Array(size).fill(null);
  return m;
}
function placeFinder(m, x, y) {
  for (let dy = -1; dy <= 7; dy++) {
    for (let dx = -1; dx <= 7; dx++) {
      const xx = x + dx, yy = y + dy;
      if (xx < 0 || yy < 0 || xx >= m.length || yy >= m.length) continue;
      const isBorder = (dx === -1 || dx === 7 || dy === -1 || dy === 7);
      const inOuter = (dx >= 0 && dx <= 6 && dy >= 0 && dy <= 6);
      const inInner = (dx >= 2 && dx <= 4 && dy >= 2 && dy <= 4);
      const inMiddle = inOuter && (dx === 0 || dx === 6 || dy === 0 || dy === 6);
      m[yy][xx] = (inInner || inMiddle) ? 1 : 0;
    }
  }
}
function placeAlignment(m, version) {
  if (version === 1) return;
  const positions = ALIGN_POSITIONS[version - 1];
  for (const cy of positions) {
    for (const cx of positions) {
      // Skip if overlaps with finder
      if ((cx === 6 && cy === 6) || (cx === 6 && cy === positions[positions.length - 1]) || (cx === positions[positions.length - 1] && cy === 6)) continue;
      if (m[cy][cx] !== null) continue;
      for (let dy = -2; dy <= 2; dy++) {
        for (let dx = -2; dx <= 2; dx++) {
          const isOnBorder = (Math.abs(dx) === 2 || Math.abs(dy) === 2);
          const isCenter = (dx === 0 && dy === 0);
          m[cy + dy][cx + dx] = (isOnBorder || isCenter) ? 1 : 0;
        }
      }
    }
  }
}
const ALIGN_POSITIONS = [
  null, [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
  [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50],
];
function placeTimingPatterns(m) {
  for (let i = 8; i < m.length - 8; i++) {
    const v = (i % 2 === 0) ? 1 : 0;
    if (m[6][i] === null) m[6][i] = v;
    if (m[i][6] === null) m[i][6] = v;
  }
}
function reserveFormatInfo(m) {
  const sz = m.length;
  for (let i = 0; i < 9; i++) {
    if (m[8][i] === null) m[8][i] = 0;
    if (m[i][8] === null) m[i][8] = 0;
  }
  for (let i = 0; i < 8; i++) {
    if (m[8][sz - 1 - i] === null) m[8][sz - 1 - i] = 0;
    if (m[sz - 1 - i][8] === null) m[sz - 1 - i][8] = 0;
  }
  m[sz - 8][8] = 1;  // dark module
}
function placeData(m, codewords) {
  const sz = m.length;
  let bitIdx = 0;
  for (let col = sz - 1; col > 0; col -= 2) {
    if (col === 6) col--;  // Skip vertical timing column
    for (let yStep = 0; yStep < sz; yStep++) {
      const upward = ((Math.floor((sz - 1 - col) / 2) % 2) === 0);
      const y = upward ? sz - 1 - yStep : yStep;
      for (let i = 0; i < 2; i++) {
        const x = col - i;
        if (m[y][x] === null) {
          const byteIdx = bitIdx >> 3;
          const bitInByte = 7 - (bitIdx & 7);
          const bit = (codewords[byteIdx] >> bitInByte) & 1;
          m[y][x] = bit;
          bitIdx++;
        }
      }
    }
  }
}

// ─── masking ────────────────────────────────────────────────────────────────
const MASK_FUNCS = [
  (r, c) => (r + c) % 2 === 0,
  (r, c) => r % 2 === 0,
  (r, c) => c % 3 === 0,
  (r, c) => (r + c) % 3 === 0,
  (r, c) => (Math.floor(r / 2) + Math.floor(c / 3)) % 2 === 0,
  (r, c) => ((r * c) % 2) + ((r * c) % 3) === 0,
  (r, c) => (((r * c) % 2) + ((r * c) % 3)) % 2 === 0,
  (r, c) => (((r + c) % 2) + ((r * c) % 3)) % 2 === 0,
];
function applyMask(m, dataMask, mask) {
  const fn = MASK_FUNCS[mask];
  for (let r = 0; r < m.length; r++) {
    for (let c = 0; c < m.length; c++) {
      if (dataMask[r][c]) m[r][c] ^= (fn(r, c) ? 1 : 0);
    }
  }
}
function buildDataMask(reserved) {
  const sz = reserved.length;
  const m = new Array(sz);
  for (let i = 0; i < sz; i++) m[i] = new Array(sz).fill(false);
  for (let r = 0; r < sz; r++) {
    for (let c = 0; c < sz; c++) {
      if (reserved[r][c] === null) m[r][c] = true;
    }
  }
  return m;
}

// ─── format info bits (15-bit BCH) ─────────────────────────────────────────
function formatInfoBits(eccLevel, mask) {
  // ECC level L = 01
  const data = (0b01 << 3) | mask;
  let rem = data;
  for (let i = 0; i < 10; i++) {
    rem = (rem << 1) ^ ((rem >> 9) === 1 ? 0b10100110111 : 0);
  }
  const bits = ((data << 10) | rem) ^ 0b101010000010010;
  return bits;
}
function placeFormatInfo(m, eccLevel, mask) {
  const bits = formatInfoBits(eccLevel, mask);
  const sz = m.length;
  // Around top-left finder
  for (let i = 0; i < 6; i++) m[8][i] = (bits >> i) & 1;
  m[8][7] = (bits >> 6) & 1;
  m[8][8] = (bits >> 7) & 1;
  m[7][8] = (bits >> 8) & 1;
  for (let i = 9; i < 15; i++) m[14 - i][8] = (bits >> i) & 1;
  // Top-right + bottom-left
  for (let i = 0; i < 8; i++) m[sz - 1 - i][8] = (bits >> i) & 1;
  for (let i = 8; i < 15; i++) m[8][sz - 15 + i] = (bits >> i) & 1;
}

// ─── pick lowest-penalty mask ──────────────────────────────────────────────
function evaluatePenalty(m) {
  // Simplified penalty: just count adjacency runs. Good enough for our use.
  const sz = m.length;
  let p = 0;
  for (let r = 0; r < sz; r++) {
    let run = 1;
    for (let c = 1; c < sz; c++) {
      if (m[r][c] === m[r][c - 1]) {
        run++;
        if (run === 5) p += 3;
        else if (run > 5) p += 1;
      } else run = 1;
    }
  }
  for (let c = 0; c < sz; c++) {
    let run = 1;
    for (let r = 1; r < sz; r++) {
      if (m[r][c] === m[r - 1][c]) {
        run++;
        if (run === 5) p += 3;
        else if (run > 5) p += 1;
      } else run = 1;
    }
  }
  return p;
}

// ─── main ───────────────────────────────────────────────────────────────────
export function generateQrSvg(text, sizePx = 200) {
  // Pick smallest version that fits at ECC L
  const len = new TextEncoder().encode(text).length;
  let version = -1;
  for (let v = 1; v <= 10; v++) {
    // Account for header overhead: 4 (mode) + 8/16 (cc) + len*8 bits
    const headerBits = 4 + (v <= 9 ? 8 : 16);
    if (len * 8 + headerBits + 4 <= capacityBits(v)) { version = v; break; }
  }
  if (version === -1) throw new Error('Payload too large for QR (max ~270 bytes at ECC L)');

  const data = encodeData(text, version);
  const codewords = makeFinalCodewords(data, version);

  const sz = 17 + 4 * version;
  const m = newMatrix(sz);
  placeFinder(m, 0, 0);
  placeFinder(m, sz - 7, 0);
  placeFinder(m, 0, sz - 7);
  placeAlignment(m, version);
  placeTimingPatterns(m);
  reserveFormatInfo(m);
  const reserved = m.map(row => row.slice());
  placeData(m, codewords);

  // Try all 8 masks, pick lowest penalty
  const dataMask = buildDataMask(reserved);
  let bestMask = 0, bestPenalty = Infinity, bestMatrix = null;
  for (let mask = 0; mask < 8; mask++) {
    const candidate = m.map(row => row.slice());
    applyMask(candidate, dataMask, mask);
    placeFormatInfo(candidate, 0, mask);
    const p = evaluatePenalty(candidate);
    if (p < bestPenalty) {
      bestPenalty = p;
      bestMask = mask;
      bestMatrix = candidate;
    }
  }

  return matrixToSvg(bestMatrix, sizePx);
}

function matrixToSvg(m, sizePx) {
  const sz = m.length;
  const margin = 1;
  const total = sz + margin * 2;
  const cell = sizePx / total;
  let rects = '';
  for (let r = 0; r < sz; r++) {
    for (let c = 0; c < sz; c++) {
      if (m[r][c] === 1) {
        const x = ((c + margin) * cell).toFixed(2);
        const y = ((r + margin) * cell).toFixed(2);
        const w = cell.toFixed(2);
        rects += `<rect x="${x}" y="${y}" width="${w}" height="${w}" fill="#0d0f1a"/>`;
      }
    }
  }
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${sizePx}" height="${sizePx}" viewBox="0 0 ${sizePx} ${sizePx}"><rect width="${sizePx}" height="${sizePx}" fill="#fff"/>${rects}</svg>`;
}
