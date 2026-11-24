#!/usr/bin/env bash
# Build an upload-ready zip of the gateway (extract into the WHMCS root directory).
set -euo pipefail
cd "$(dirname "$0")"
version=$(grep -oE "POKPAY_WHMCS_VERSION', '[^']+" modules/gateways/pokpay/lib/Gateway.php | cut -d"'" -f3)
mkdir -p dist
out="dist/pokpay-whmcs-${version}.zip"
rm -f "$out"
zip -rq "$out" modules README.md -x '*.DS_Store'
echo "$out"
