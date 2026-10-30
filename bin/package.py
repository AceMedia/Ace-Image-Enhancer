#!/usr/bin/env python3
"""Build the distributable without tests, source maps or development files."""
from pathlib import Path
import hashlib
import zipfile
root = Path(__file__).resolve().parents[1]
out = root / 'dist' / 'ace-image-enhancer-1.4.1.zip'
out.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(root.rglob('*')):
        relative = path.relative_to(root)
        if path.is_file() and relative.parts[0] in ['ace-image-enhancer.php', 'readme.txt', 'LICENSE', 'css', 'js', 'svgedit'] and not path.name.endswith('.map'):
            info = zipfile.ZipInfo('ace-image-enhancer/' + relative.as_posix(), (2026, 10, 30, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, path.read_bytes())
print(out)
print(hashlib.sha256(out.read_bytes()).hexdigest())
