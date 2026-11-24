#!/usr/bin/env bash
# Build the release zip:
#   upload/modules/...  -> copy the CONTENTS of upload/ into the WHMCS root directory
#   README.md, INSTALL.txt
set -euo pipefail
cd "$(dirname "$0")"
version=$(grep -oE "POKPAY_WHMCS_VERSION', '[^']+" modules/gateways/pokpay/lib/Gateway.php | cut -d"'" -f3)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/upload" dist
cp -R modules "$stage/upload/"
cp README.md "$stage/"
cat > "$stage/INSTALL.txt" <<TXT
POK (PokPay) payment gateway for WHMCS - version ${version}

1. Copy the CONTENTS of the "upload" folder into your WHMCS root directory
   (the folder that contains configuration.php). It adds:
     modules/gateways/pokpay.php
     modules/gateways/pokpay/
     modules/gateways/callback/pokpay.php
2. WHMCS admin: open Payment Gateways, activate "POK (PokPay) - Card Payments".
3. Enter Merchant ID, Key ID, Key Secret (POK Business Panel > E-payments > API Keys).
   Tick "Sandbox / Staging" while testing with staging keys.
4. Test: pay an invoice with card 4111 1111 1111 1111, any future expiry, any CVV.
5. Go live: enter production keys and untick Sandbox.

Full documentation: README.md

--- Shqip ---
1. Kopjoni PERMBAJTJEN e dosjes "upload" ne rrenjen e WHMCS (ku ndodhet configuration.php).
2. Ne panelin e adminit: Payment Gateways > aktivizoni "POK (PokPay) - Card Payments".
3. Vendosni Merchant ID, Key ID dhe Key Secret (POK Business Panel > E-payments > API Keys).
   Aktivizoni "Sandbox / Staging" gjate testimit me celesat e staging.
4. Testoni me karten 4111 1111 1111 1111 (cdo date e ardhshme, cdo CVV).
5. Per pagesa reale: vendosni celesat e production dhe c'aktivizoni Sandbox.
TXT
out="$PWD/dist/pokpay-whmcs-${version}.zip"
rm -f "$out"
(cd "$stage" && zip -rq "$out" upload README.md INSTALL.txt -x '*.DS_Store')
echo "dist/pokpay-whmcs-${version}.zip"
