#!/bin/sh
# Builds unsaid.zip for uploading to a cPanel host: the committed files in a folder
# called "unsaid", with folders 755 and files 644. cPanel refuses to run PHP from
# group-writable files or folders (775/664), and every PHP page then fails with a
# "500 Internal Server Error".
#
#   sh scripts/build-zip.sh [output.zip]
set -e
out=$(realpath -m "${1:-unsaid.zip}")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir "$work/unsaid"
git -c tar.umask=0022 archive HEAD | tar -x -C "$work/unsaid"
find "$work/unsaid" -type d -exec chmod 755 {} +
find "$work/unsaid" -type f -exec chmod 644 {} +
rm -f "$out"
(cd "$work" && python3 -c "import shutil, sys; shutil.make_archive(sys.argv[1][:-4], 'zip', '.', 'unsaid')" "$out")
echo "Wrote $out"
