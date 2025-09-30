#!/usr/bin/env bash
# install_model.sh
# Usage: ./install_model.sh /path/to/model.joblib
# Copies the given model.joblib to the plugin directory as model.joblib

set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "Usage: $0 /path/to/model.joblib" >&2
  exit 2
fi

SRC="$1"
DEST_DIR="$(cd "$(dirname "$0")" && pwd)"
DEST="$DEST_DIR/model.joblib"

if [ ! -f "$SRC" ]; then
  echo "Source model not found: $SRC" >&2
  exit 3
fi

cp -v "$SRC" "$DEST"
chmod 644 "$DEST"
echo "Installed model to $DEST"
