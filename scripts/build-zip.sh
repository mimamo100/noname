#!/bin/sh
# Builds unsaid.zip for uploading to a cPanel host: the committed files in a folder
# called "unsaid", with folders 755 and files 644, and no .htaccess files (the owner keeps
# their own on the server). cPanel refuses to run PHP from
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
find "$work/unsaid" -name .htaccess -type f -delete
find "$work/unsaid" -type d -exec chmod 755 {} +
find "$work/unsaid" -type f -exec chmod 644 {} +
rm -f "$out"
# python3 can be a store shortcut on Windows that does nothing; fall back to python.
py=python3
"$py" -c "" 2>/dev/null || py=python
# Written with Python setting each entry's permissions itself (folders 755, files 644), as
# chmod doesn't take effect on Windows and cPanel won't run group-writable PHP.
(cd "$work" && "$py" - "$out" <<'PY'
import os, sys, zipfile
with zipfile.ZipFile(sys.argv[1], 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk('unsaid'):
        dirs.sort()
        d = zipfile.ZipInfo(root.replace(os.sep, '/') + '/')
        d.create_system = 3  # Unix, or unzip ignores the permissions
        d.external_attr = (0o40755 << 16) | 0x10
        z.writestr(d, '')
        for name in sorted(files):
            path = os.path.join(root, name)
            info = zipfile.ZipInfo.from_file(path, path.replace(os.sep, '/'))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            with open(path, 'rb') as f:
                z.writestr(info, f.read())
PY
)
echo "Wrote $out"
