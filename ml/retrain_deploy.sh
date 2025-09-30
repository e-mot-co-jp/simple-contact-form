#!/usr/bin/env bash
# retrain_deploy.sh
# 使い方: ml ディレクトリで実行するか、フルパスで実行
# 概要: 1) WP DB から spam_list を CSV にエクスポート
#       2) train_tfidf_lr.py で学習し model.joblib を作成
#       3) 成功したら atomically で plugin root に設置
#       4) ログを /tmp/scf_retrain.log に書く

set -euo pipefail

HERE_DIR="$(cd "$(dirname "$0")" && pwd)"
ML_DIR="$HERE_DIR"
PLUGIN_ROOT="$(cd "$HERE_DIR/.." && pwd)"
LOGFILE="/tmp/scf_retrain.log"
TIMESTAMP="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# 環境変数で PYTHON_BIN を指定できる（推奨）
PYTHON_BIN=${PYTHON_BIN:-python3}
VENV_DIR="$ML_DIR/.venv"
if [ -x "$VENV_DIR/bin/python" ]; then
  PYTHON_BIN="$VENV_DIR/bin/python"
fi

CSV_PATH="$ML_DIR/spam_list.csv"
TRAIN_SCRIPT="$ML_DIR/train_tfidf_lr.py"
NEW_MODEL="$ML_DIR/model.joblib"
DEPLOY_TARGET="$PLUGIN_ROOT/model.joblib"

echo "[$TIMESTAMP] retrain_deploy started (python=$PYTHON_BIN)" >> "$LOGFILE"

# 1) Export DB to CSV using WP-CLI if available, else try mysql client
if command -v wp >/dev/null 2>&1; then
  # Use WP-CLI -- adjust query as needed
  echo "[$TIMESTAMP] Exporting DB via wp db query" >> "$LOGFILE"
  wp db query "SELECT message AS message, class AS label FROM spam_list;" --skip-column-names --path="$PLUGIN_ROOT/.." | awk -F '\t' 'BEGIN{OFS=","}{gsub(/\"/,"\"\"",$0); print "\""$1"\",\""$2"\""}' > "$CSV_PATH"
else
  # Try mysql client (needs env vars or .my.cnf)
  echo "[$TIMESTAMP] WP-CLI not found; attempting mysql client" >> "$LOGFILE"
  # User must configure these env vars or edit the script
  : ${DB_USER:?}
  : ${DB_PASS:?}
  : ${DB_NAME:?}
  : ${DB_HOST:='localhost'}
  mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -e "SELECT message, class FROM spam_list" "$DB_NAME" | awk -F '\t' 'BEGIN{OFS=","}{gsub(/\"/,"\"\"",$0); print "\""$1"\",\""$2"\""}' > "$CSV_PATH"
fi

# Quick sanity
if [ ! -s "$CSV_PATH" ]; then
  echo "[$TIMESTAMP] ERROR: CSV export failed or empty" >> "$LOGFILE"
  exit 4
fi

# 2) Train
echo "[$TIMESTAMP] Training model" >> "$LOGFILE"
cd "$ML_DIR"
"$PYTHON_BIN" "$TRAIN_SCRIPT" --input "$CSV_PATH" --output "$NEW_MODEL" >> "$LOGFILE" 2>&1 || { echo "[$TIMESTAMP] ERROR: training failed" >> "$LOGFILE"; exit 5; }

# 3) Deploy atomically
if [ -f "$NEW_MODEL" ]; then
  TMP_DEPLOY="$DEPLOY_TARGET.tmp.$(date +%s)"
  cp -v "$NEW_MODEL" "$TMP_DEPLOY" >> "$LOGFILE" 2>&1
  mv -v "$TMP_DEPLOY" "$DEPLOY_TARGET" >> "$LOGFILE" 2>&1
  chmod 644 "$DEPLOY_TARGET"
  echo "[$TIMESTAMP] Deployed model to $DEPLOY_TARGET" >> "$LOGFILE"
else
  echo "[$TIMESTAMP] ERROR: new model not found" >> "$LOGFILE"
  exit 6
fi

echo "[$TIMESTAMP] retrain_deploy completed" >> "$LOGFILE"
