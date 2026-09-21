/**
 * Collapse DOM-captured whitespace into single spaces + trim — i.e. what CSS
 * `white-space: normal` renders for the original element.
 *
 * `TextContext.text` is the RAW `textContent` of the Elementor target and
 * carries HTML source formatting (newlines + tab indentation around the text
 * node). Vendor components are 1:1 ReactBits and expect authored prop text;
 * whitespace-sensitive renderers (pre-wrap, per-char splits, word tokenizers)
 * turn the raw formatting into phantom line breaks, scrambled glyphs or empty
 * tokens. NBSP → regular space is deliberate: DecryptedText preserves only
 * `' '` during scramble, and word-splitting vendors split on plain spaces.
 *
 * Same expression as the pre-existing ad-hoc normalizations in rotating-text
 * and fuzzy-text (which keep their own handling — rotating-text needs the raw
 * newlines for its `splitBy: 'lines'` mode).
 */
export function normalizeFxText(text: string): string {
  return text.replace(/\s+/g, ' ').trim();
}
