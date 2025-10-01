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

find_wp_root() {
  # start at plugin root and walk up to a few levels to find wp-load.php
  local d="$1"
  for i in 1 2 3 4 5; do
    if [ -f "$d/wp-load.php" ] || [ -f "$d/wp-config.php" ]; then
      echo "$d"
      return 0
    fi
    d="$(cd "$d/.." && pwd)"
  done
  return 1
}

# 1) Export DB to CSV using WP-CLI if available, else try mysql client
if command -v wp >/dev/null 2>&1; then
  # Allow manual override via environment variable
  if [ -n "${WP_ROOT:-}" ]; then
    echo "[$TIMESTAMP] Using WP_ROOT from environment: $WP_ROOT" >> "$LOGFILE"
  else
    # locate WordPress root (where wp-load.php lives)
    WP_ROOT="$(find_wp_root "$PLUGIN_ROOT")" || true
    if [ -z "$WP_ROOT" ]; then
      # fallback: assume two levels up (plugin -> plugins -> wp-content -> wp-root)
      WP_ROOT="$PLUGIN_ROOT/../.."
      echo "[$TIMESTAMP] Warning: automatic WP root detection failed; falling back to $WP_ROOT" >> "$LOGFILE"
    else
      echo "[$TIMESTAMP] Detected WP_ROOT: $WP_ROOT" >> "$LOGFILE"
    fi
  fi

  # verify the detected WP_ROOT looks like a WordPress install
  if [ ! -f "$WP_ROOT/wp-load.php" ] && [ ! -f "$WP_ROOT/wp-config.php" ]; then
    echo "[$TIMESTAMP] ERROR: WP_ROOT ($WP_ROOT) does not contain wp-load.php or wp-config.php." >> "$LOGFILE"
    echo "[$TIMESTAMP] Hint: set WP_ROOT to your WordPress install and re-run, e.g.: export WP_ROOT=~/e-mot.co.jp/public_html/dev" >> "$LOGFILE"
    echo "[$TIMESTAMP] Aborting due to invalid WP_ROOT." >> "$LOGFILE"
    exit 3
  fi

  WP_ROOT="$(cd "$WP_ROOT" && pwd)"
  echo "[$TIMESTAMP] Exporting DB via wp db query (wp root: $WP_ROOT)" >> "$LOGFILE"
  # send wp stderr to logfile (deprecation warnings), stdout is piped to awk
  wp db query "SELECT message AS message, class AS label FROM spam_list;" --skip-column-names --path="$WP_ROOT" 2>>"$LOGFILE" | awk -F '\t' 'BEGIN{OFS=","}{gsub(/\"/,"\"\"",$0); print "\""$1"\",\""$2"\""}' > "$CSV_PATH"
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

# Ensure CSV has header row expected by train_tfidf_lr.py
# Quick pre-filter: remove rows with empty label or completely empty rows (fix leading "","" case)
if [ -s "$CSV_PATH" ]; then
  PREF_OUT="$CSV_PATH.pref.$$"
  echo "[$TIMESTAMP] Running quick prefilter to remove empty-label rows" >> "$LOGFILE"
  "$PYTHON_BIN" - "$CSV_PATH" "$PREF_OUT" <<'PY' 2>>"$LOGFILE" || true
import csv,sys
infn=sys.argv[1]
outfn=sys.argv[2]
try:
    with open(infn,newline='',encoding='utf-8') as inf, open(outfn,'w',newline='',encoding='utf-8') as outf:
        rdr=csv.reader(inf)
        w=csv.writer(outf,quoting=csv.QUOTE_ALL)
        for row in rdr:
            if not row:
                continue
            # keep rows that have at least two columns and non-empty label
            if len(row) >= 2 and row[1].strip() != '':
                w.writerow(row)
except Exception:
    # fallback: do nothing
    pass
PY
  # if prefilter produced non-empty file, replace
  if [ -s "$PREF_OUT" ]; then
    mv -f "$PREF_OUT" "$CSV_PATH"
    echo "[$TIMESTAMP] Prefilter replaced CSV (removed empty-label rows)" >> "$LOGFILE"
  else
    rm -f "$PREF_OUT" 2>/dev/null || true
  fi

  
  first_line="$(head -n 1 "$CSV_PATH" | tr -d '\r' | tr -d '\n' | sed -e 's/^\s*//')"
  echo "[$TIMESTAMP] CSV first line: $first_line" >> "$LOGFILE"
  # crude check: if header doesn't contain message or text, prepend header
  echo "$first_line" | tr '[:upper:]' '[:lower:]' | grep -Eq 'message|text|label|class'
  if [ $? -ne 0 ]; then
    echo "[$TIMESTAMP] CSV appears to lack header; prepending \"message,label\"" >> "$LOGFILE"
    TMP_CSV="$CSV_PATH.tmp.$$"
    printf '"message","label"\n' > "$TMP_CSV"
    cat "$CSV_PATH" >> "$TMP_CSV"
    mv "$TMP_CSV" "$CSV_PATH"
  else
    echo "[$TIMESTAMP] CSV header looks OK" >> "$LOGFILE"
  fi
fi

# Sanitize CSV: remove rows without valid label and filter out PHP/WP-CLI noise lines
echo "[$TIMESTAMP] Sanitizing CSV (filter spam/ham rows, remove deprecated noise)" >> "$LOGFILE"
SANITIZED="$CSV_PATH.sanitized.$$"
KEPT_COUNT=$("$PYTHON_BIN" - "$CSV_PATH" "$SANITIZED" <<'PY' 2>>"$LOGFILE"
import csv,sys
infn = sys.argv[1]
outfn = sys.argv[2]
kept = 0
try:
  with open(infn, newline='', encoding='utf-8') as inf, open(outfn, 'w', newline='', encoding='utf-8') as outf:
    reader = csv.reader(inf)
    writer = csv.writer(outf, quoting=csv.QUOTE_ALL)
    header = next(reader, None)
    if header is None:
      print(0)
      sys.exit(0)
    writer.writerow(['message','label'])
    for row in reader:
      if not row or len(row) < 2:
        continue
      msg = row[0].strip()
      lbl = row[1].strip().lower()
      if not lbl or lbl not in ('spam','ham'):
        continue
      low = msg.lower()
      if 'deprecated:' in low or 'phar://' in low:
        continue
      writer.writerow([msg, lbl])
      kept += 1
  print(kept)
except Exception:
  print(0)
  sys.exit(0)
PY
)
KEPT_COUNT=$(echo "$KEPT_COUNT" | tr -d '\r\n' || true)
echo "[$TIMESTAMP] Sanitization produced rows kept: $KEPT_COUNT" >> "$LOGFILE"
if [ -n "$KEPT_COUNT" ] && [ "$KEPT_COUNT" -gt 0 ] 2>/dev/null; then
  mv -f "$SANITIZED" "$CSV_PATH"
  echo "[$TIMESTAMP] Replaced CSV with sanitized version (kept=$KEPT_COUNT)" >> "$LOGFILE"
else
  echo "[$TIMESTAMP] Sanitization resulted in 0 rows; leaving original CSV in place" >> "$LOGFILE"
  rm -f "$SANITIZED" 2>/dev/null || true
fi

# Quick sanity
if [ ! -s "$CSV_PATH" ]; then
  echo "[$TIMESTAMP] ERROR: CSV export failed or empty" >> "$LOGFILE"
  exit 4
fi

# If sanitizer removed all rows, attempt fallback: export from scf_inquiries table
if [ -z "$KEPT_COUNT" ] || [ "$KEPT_COUNT" -eq 0 ] 2>/dev/null; then
  echo "[$TIMESTAMP] Sanitizer kept 0 rows — attempting fallback export from scf_inquiries" >> "$LOGFILE"
  if command -v wp >/dev/null 2>&1; then
    PREFIX=$(wp db prefix --path="$WP_ROOT" 2>>"$LOGFILE" | tr -d '\r\n' || true)
    if [ -z "$PREFIX" ]; then
      PREFIX='wp_'
    fi
    TABLE="${PREFIX}scf_inquiries"
    echo "[$TIMESTAMP] Exporting from table: $TABLE" >> "$LOGFILE"
    wp db query "SELECT CONCAT_WS(' \\n ', COALESCE(inquiry, ''), COALESCE(product, ''), COALESCE(content, '')) AS message, CASE WHEN COALESCE(is_spam,0)=1 THEN 'spam' ELSE 'ham' END AS label FROM ${TABLE};" --skip-column-names --path="$WP_ROOT" 2>>"$LOGFILE" | awk -F '\t' 'BEGIN{OFS=","}{gsub(/\"/,"\"\"",$0); print "\""$1"\",\""$2"\""}' > "$CSV_PATH" || true
  else
    # mysql fallback if environment variables provided
    if [ -n "${DB_NAME:-}" ] && [ -n "${DB_USER:-}" ]; then
      PREFIX=${WP_DB_PREFIX:-'wp_'}
      TABLE="${PREFIX}scf_inquiries"
      echo "[$TIMESTAMP] Using mysql client to export from $TABLE" >> "$LOGFILE"
      mysql -u "$DB_USER" -p"$DB_PASS" -h "${DB_HOST:-localhost}" -e "SELECT CONCAT_WS(' \\n ', COALESCE(inquiry, ''), COALESCE(product, ''), COALESCE(content, '')) AS message, IF(COALESCE(is_spam,0)=1,'spam','ham') AS label FROM ${TABLE}" "$DB_NAME" | awk -F '\t' 'BEGIN{OFS=","}{gsub(/\"/,"\"\"",$0); print "\""$1"\",\""$2"\""}' > "$CSV_PATH" || true
    else
      echo "[$TIMESTAMP] No WP-CLI and no mysql env vars; cannot fallback to scf_inquiries" >> "$LOGFILE"
    fi
  fi

  # If fallback produced CSV, ensure header and re-run sanitizer
  if [ -s "$CSV_PATH" ]; then
    echo "[$TIMESTAMP] Fallback CSV generated; ensuring header and sanitizing" >> "$LOGFILE"
    first_line2="$(head -n 1 "$CSV_PATH" | tr -d '\r' | tr -d '\n' | sed -e 's/^\s*//')"
    echo "[$TIMESTAMP] Fallback CSV first line: $first_line2" >> "$LOGFILE"
    echo "$first_line2" | tr '[:upper:]' '[:lower:]' | grep -Eq 'message|text|label|class'
    if [ $? -ne 0 ]; then
      echo "[$TIMESTAMP] Fallback CSV lacks header; prepending header" >> "$LOGFILE"
      TMP_CSV2="$CSV_PATH.tmp.$$"
      printf '"message","label"\n' > "$TMP_CSV2"
      cat "$CSV_PATH" >> "$TMP_CSV2"
      mv "$TMP_CSV2" "$CSV_PATH"
    else
      echo "[$TIMESTAMP] Fallback CSV header looks OK" >> "$LOGFILE"
    fi

    # re-run sanitizer (same python snippet)
    SANITIZED2="$CSV_PATH.sanitized2.$$"
    NEW_KEPT=$("$PYTHON_BIN" - "$CSV_PATH" "$SANITIZED2" <<'PY' 2>>"$LOGFILE"
import csv,sys
infn = sys.argv[1]
outfn = sys.argv[2]
kept = 0
try:
    with open(infn, newline='', encoding='utf-8') as inf, open(outfn, 'w', newline='', encoding='utf-8') as outf:
        reader = csv.reader(inf)
        writer = csv.writer(outf, quoting=csv.QUOTE_ALL)
        header = next(reader, None)
        if header is None:
            print(0)
            sys.exit(0)
        writer.writerow(['message','label'])
        for row in reader:
            if not row or len(row) < 2:
                continue
            msg = row[0].strip()
            lbl = row[1].strip().lower()
            if not lbl or lbl not in ('spam','ham'):
                continue
            low = msg.lower()
            if 'deprecated:' in low or 'phar://' in low:
                continue
            writer.writerow([msg, lbl])
            kept += 1
    print(kept)
except Exception:
    print(0)
    sys.exit(0)
PY
)
    NEW_KEPT=$(echo "$NEW_KEPT" | tr -d '\r\n' || true)
    echo "[$TIMESTAMP] Fallback sanitization kept: $NEW_KEPT" >> "$LOGFILE"
    if [ -n "$NEW_KEPT" ] && [ "$NEW_KEPT" -gt 0 ] 2>/dev/null; then
      mv -f "$SANITIZED2" "$CSV_PATH"
      echo "[$TIMESTAMP] Replaced CSV with fallback-sanitized version (kept=$NEW_KEPT)" >> "$LOGFILE"
      KEPT_COUNT=$NEW_KEPT
    else
      echo "[$TIMESTAMP] Fallback sanitization produced 0 rows; aborting" >> "$LOGFILE"
      rm -f "$SANITIZED2" 2>/dev/null || true
      exit 4
    fi
  else
    echo "[$TIMESTAMP] Fallback did not produce CSV or produced empty CSV" >> "$LOGFILE"
    exit 4
  fi
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
