#!/bin/bash
# Download @xenova/transformers runtime + all-MiniLM-L6-v2 for offline category classification.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
VENDOR="$ROOT/assets/vendor/transformers"
MODEL="$VENDOR/Xenova/all-MiniLM-L6-v2"
ONNX="$MODEL/onnx"
BASE="https://huggingface.co/Xenova/all-MiniLM-L6-v2/resolve/main"

mkdir -p "$ONNX"

echo "→ transformers.min.js"
curl -fsSL "https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2/dist/transformers.min.js" \
  -o "$VENDOR/transformers.min.js"

for f in config.json tokenizer.json tokenizer_config.json; do
  echo "→ $f"
  curl -fsSL "$BASE/$f" -o "$MODEL/$f"
done

echo "→ onnx/model_quantized.onnx (~22 MB)"
curl -fsSL "$BASE/onnx/model_quantized.onnx" -o "$ONNX/model_quantized.onnx"

chown -R www-data:www-data "$VENDOR" 2>/dev/null || true
echo "Done. Model installed under assets/vendor/transformers/"
