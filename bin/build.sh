#!/bin/sh
# Crea dist/lu3g-mail.zip (cartella lu3g-mail/ all'interno), il pacchetto che WordPress installa e aggiorna.
# Esclude i file che servono solo allo sviluppo (git, script, automazioni).
set -e
cd "$(dirname "$0")/.."
rm -rf dist && mkdir -p dist/lu3g-mail
cp -R lu3g-mail.php readme.txt uninstall.php includes assets dist/lu3g-mail/
(cd dist && zip -rq lu3g-mail.zip lu3g-mail -x '*.DS_Store')
rm -rf dist/lu3g-mail
echo "dist/lu3g-mail.zip"
