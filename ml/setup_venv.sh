#!/usr/bin/env bash
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
VENV="$HERE/.venv"
REQ="$HERE/requirements.txt"
LOG="$HERE/setup_venv.log"
PY=${PYTHON_BIN:-python3}

echo "Setting up venv in $VENV" | tee -a "$LOG"
if [ -d "$VENV" ]; then
  echo "Venv already exists at $VENV" | tee -a "$LOG"
else
  if ! command -v "$PY" >/dev/null 2>&1; then
    echo "Error: python binary not found: $PY" | tee -a "$LOG" >&2
    exit 2
  fi
  "$PY" -m venv "$VENV" 2>>"$LOG" || { echo "Failed to create venv" | tee -a "$LOG" >&2; exit 3; }
  echo "Created venv" | tee -a "$LOG"
fi

# Activate and install
# Note: use the venv's python to avoid activation side-effects in non-interactive shells
PIP="$VENV/bin/python -m pip"
# upgrade pip first
$VENV/bin/python -m pip install --upgrade pip setuptools wheel 2>>"$LOG" || true
if [ -f "$REQ" ]; then
  echo "Installing from $REQ" | tee -a "$LOG"
  $VENV/bin/python -m pip install -r "$REQ" 2>>"$LOG" || { echo "pip install failed (see $LOG)" | tee -a "$LOG" >&2; exit 4; }
else
  echo "Warning: $REQ not found; create it or provide packages manually" | tee -a "$LOG"
fi

echo "Venv setup complete. To use it:" | tee -a "$LOG"
echo "  source $VENV/bin/activate" | tee -a "$LOG"
echo "Then run training with: python ml/train_tfidf_lr.py --input ml/spam_list.csv --output ml/model.joblib" | tee -a "$LOG"

exit 0
