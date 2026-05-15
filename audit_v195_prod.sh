#!/usr/bin/env bash
set -euo pipefail

# gddealer Phase 1 audit — captures prod state for comparison against git.
# Run from: /home/gunrack/domains/gunrack.deals/public_html/
# Read-only. No DB mutations, no filesystem writes under APP_DIR.

APP_DIR="applications/gddealer"
CONF="conf_global.php"
OUT_DIR="/tmp/gddealer_audit_$(date +%Y%m%d_%H%M%S)"

if [ ! -f "$CONF" ]; then
    echo "FATAL: $CONF not found. Run this from the IPS public_html root." >&2
    exit 1
fi

if [ ! -d "$APP_DIR" ]; then
    echo "FATAL: $APP_DIR not found." >&2
    exit 1
fi

# Parse DB credentials from conf_global.php
eval "$(php -r '
include "'"$CONF"'";
echo "DB_USER=" . escapeshellarg($INFO["sql_user"]) . "\n";
echo "DB_HOST=" . escapeshellarg($INFO["sql_host"]) . "\n";
echo "DB_NAME=" . escapeshellarg($INFO["sql_database"]) . "\n";
echo "DB_PASS=" . escapeshellarg($INFO["sql_pass"]) . "\n";
echo "PREFIX="  . escapeshellarg($INFO["sql_tbl_prefix"] ?? "") . "\n";
')"

export MYSQL_PWD="$DB_PASS"

mysql_q() {
    mysql -u"$DB_USER" -h"$DB_HOST" "$DB_NAME" "$@"
}

echo "=== gddealer Phase 1 audit ==="
echo "OUT_DIR: $OUT_DIR"
echo "DB: $DB_NAME  PREFIX: $PREFIX"
echo ""

mkdir -p "$OUT_DIR"/{db/show_create,db/templates,source,state}

# =====================================================================
# A. Schema capture
# =====================================================================
echo "[A] Schema capture..."

mysql_q -BN -e "SHOW TABLES LIKE '${PREFIX}gd_%';" > "$OUT_DIR/db/tables.txt"

TABLE_COUNT=0
while IFS= read -r tbl; do
    [ -z "$tbl" ] && continue
    mysql_q -BN -e "SHOW CREATE TABLE \`${tbl}\`;" \
        | cut -f2- \
        > "$OUT_DIR/db/show_create/${tbl}.sql" 2>/dev/null || true
    TABLE_COUNT=$((TABLE_COUNT + 1))
done < "$OUT_DIR/db/tables.txt"

echo "  Tables captured: $TABLE_COUNT"

# =====================================================================
# B. Template capture
# =====================================================================
echo "[B] Template capture..."

mysql_q -B -e "
SELECT template_id, template_set_id, template_location,
       template_group, template_name, template_data,
       LENGTH(template_content) AS len,
       MD5(template_content) AS hash
FROM ${PREFIX}core_theme_templates
WHERE template_app = 'gddealer'
ORDER BY template_location, template_group, template_name;
" > "$OUT_DIR/db/templates_index.tsv"

TEMPLATE_COUNT=0
while IFS=$'\t' read -r tid tset tloc tgrp tname tdata tlen thash; do
    [ "$tid" = "template_id" ] && continue
    [ -z "$tid" ] && continue
    fname="${tloc}__${tgrp}__${tname}__${tset}.txt"
    mysql_q -BN -e "
        SELECT template_content
        FROM ${PREFIX}core_theme_templates
        WHERE template_id = ${tid};
    " > "$OUT_DIR/db/templates/${fname}" 2>/dev/null || true
    TEMPLATE_COUNT=$((TEMPLATE_COUNT + 1))
done < "$OUT_DIR/db/templates_index.tsv"

echo "  Templates captured: $TEMPLATE_COUNT"

# =====================================================================
# C. Lang words capture
# =====================================================================
echo "[C] Lang words capture..."

mysql_q -B -e "
SELECT word_key, word_default, word_js, word_export
FROM ${PREFIX}core_sys_lang_words
WHERE word_app = 'gddealer' AND lang_id = 1
ORDER BY word_key;
" > "$OUT_DIR/db/lang_words.tsv"

LANG_COUNT=$(( $(wc -l < "$OUT_DIR/db/lang_words.tsv") - 1 ))
[ "$LANG_COUNT" -lt 0 ] && LANG_COUNT=0
echo "  Lang words captured: $LANG_COUNT"

# =====================================================================
# D. Source file capture
# =====================================================================
echo "[D] Source file capture..."

rsync -a \
    --include='*/' \
    --include='*.php' \
    --include='*.json' \
    --include='*.xml' \
    --include='*.html' \
    --include='*.js' \
    --include='*.css' \
    --exclude='setup/upg_*/' \
    --exclude='node_modules/' \
    --exclude='.git/' \
    --exclude='tests/' \
    --exclude='_design/' \
    --exclude='*.tar' \
    --prune-empty-dirs \
    "$APP_DIR/" "$OUT_DIR/source/"

SOURCE_COUNT=0
{
    echo -e "relative_path\tsize_bytes\tmd5\tmtime"
    while IFS= read -r -d '' fpath; do
        rel="${fpath#$OUT_DIR/source/}"
        sz=$(stat -c%s "$fpath" 2>/dev/null || echo 0)
        md=$(md5sum "$fpath" 2>/dev/null | cut -d' ' -f1 || echo "?")
        mt=$(stat -c%Y "$fpath" 2>/dev/null || echo 0)
        echo -e "${rel}\t${sz}\t${md}\t${mt}"
        SOURCE_COUNT=$((SOURCE_COUNT + 1))
    done < <(find "$OUT_DIR/source/" -type f -print0 | sort -z)
} > "$OUT_DIR/source/MANIFEST.tsv"

echo "  Source files captured: $SOURCE_COUNT"

# =====================================================================
# E. Application state
# =====================================================================
echo "[E] Application state..."

mysql_q -E -e "
SELECT * FROM ${PREFIX}core_applications WHERE app_directory='gddealer';
" > "$OUT_DIR/state/app_row.txt" 2>/dev/null || true

mysql_q -B -e "
SELECT log_id, FROM_UNIXTIME(log_date) AS log_date, log_category,
       LEFT(log_message, 500) AS log_message
FROM ${PREFIX}core_log
WHERE log_category LIKE 'gddealer%'
ORDER BY log_date DESC
LIMIT 500;
" > "$OUT_DIR/state/upgrade_log.txt" 2>/dev/null || echo "(no log rows)" > "$OUT_DIR/state/upgrade_log.txt"

mysql_q -E -e "
SELECT * FROM ${PREFIX}core_queue
WHERE app='gddealer' OR \`key\` LIKE 'gddealer%';
" > "$OUT_DIR/state/queue.txt" 2>/dev/null || echo "(no queue rows)" > "$OUT_DIR/state/queue.txt"

# =====================================================================
# F. Environment
# =====================================================================
echo "[F] Environment..."

{
    echo "=== date ==="
    date -u
    echo ""
    echo "=== hostname ==="
    hostname
    echo ""
    echo "=== mysql --version ==="
    mysql --version 2>/dev/null || echo "(unknown)"
    echo ""
    echo "=== php --version ==="
    php --version 2>/dev/null || echo "(unknown)"
    echo ""
    echo "=== application.json ==="
    cat "$APP_DIR/data/application.json" 2>/dev/null || echo "(not found)"
    echo ""
    echo "=== versions.json entry count ==="
    grep -c '"10' "$APP_DIR/data/versions.json" 2>/dev/null || echo "0"
    echo ""
    echo "=== versions.json last entry ==="
    tail -3 "$APP_DIR/data/versions.json" 2>/dev/null || echo "(not found)"
} > "$OUT_DIR/state/env.txt"

# =====================================================================
# Summary
# =====================================================================
TOTAL_SIZE=$(du -sh "$OUT_DIR" | cut -f1)

echo ""
echo "========================================="
echo "  Phase 1 audit complete"
echo "========================================="
echo "  Tables captured:      $TABLE_COUNT"
echo "  Templates captured:   $TEMPLATE_COUNT"
echo "  Lang words captured:  $LANG_COUNT"
echo "  Source files captured: $SOURCE_COUNT"
echo "  Total output size:    $TOTAL_SIZE"
echo "========================================="
echo ""

# =====================================================================
# Package
# =====================================================================
echo "Creating tarball..."
tar -czf "${OUT_DIR}.tar.gz" -C "$(dirname "$OUT_DIR")" "$(basename "$OUT_DIR")"

TARBALL_SIZE=$(du -sh "${OUT_DIR}.tar.gz" | cut -f1)
echo ""
echo "Tarball: ${OUT_DIR}.tar.gz  ($TARBALL_SIZE)"
echo ""
echo "To download:"
echo "  scp -P 2200 root@108.160.146.199:${OUT_DIR}.tar.gz ./"
echo ""
