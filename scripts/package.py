#!/usr/bin/env python3
"""Create a deterministic WordPress release ZIP from an explicit source allowlist.

Run npm run package to rebuild the React bundle first. Requires only Python's stdlib.
"""
from pathlib import Path
import hashlib
import re
import zipfile

ROOT = Path(__file__).resolve().parent.parent
DIRECTORIES = ('Admin', 'Blocks', 'Rest', 'assets', 'includes', 'languages')
FILES = ('LICENSE', 'readme.txt', 'plain-forms.php', 'uninstall.php')


def package(destination: Path) -> Path:
    version = re.search(r'^ \* Version: ([0-9.]+)$', (ROOT / 'plain-forms.php').read_text(), re.M)
    if version is None:
        raise ValueError('Missing plugin version')
    sources = [ROOT / name for name in FILES]
    for directory in DIRECTORIES:
        if (ROOT / directory).is_symlink():
            raise ValueError(f'Symlinked runtime directory: {directory}')
        sources.extend(sorted((ROOT / directory).rglob('*')))
    # Explicitly retain readable JSX source alongside the compiled bundle for review.
    for required in ('assets/admin-app.js', 'assets/src/admin/index.jsx', 'Blocks/Form/block.json'):
        if not (ROOT / required).is_file():
            raise ValueError(f'Missing release input: {required}')
    destination.mkdir(parents=True, exist_ok=True)
    archive = destination / f'plain-forms-{version[1]}.zip'
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
        # Preserve declared runtime directories even when empty (notably Domain Path).
        for directory in DIRECTORIES:
            info = zipfile.ZipInfo(f'plain-forms/{directory}/', date_time=(1980, 1, 1, 0, 0, 0))
            info.external_attr = 0o40755 << 16 | 0x10
            info.create_system = 3
            output.writestr(info, b'')
        for source in sorted(sources):
            relative = source.relative_to(ROOT)
            if source.is_symlink():
                raise ValueError(f'Symlinks are not release inputs: {relative}')
            if any(part.startswith('.') or part in ('node_modules', 'vendor', '__pycache__') for part in relative.parts):
                raise ValueError(f'Development file inside runtime directory: {relative}')
            if source.is_dir():
                continue
            info = zipfile.ZipInfo('plain-forms/' + relative.as_posix(), date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            info.create_system = 3
            output.writestr(info, source.read_bytes(), compresslevel=9)
    print(f'{archive}: SHA256 {hashlib.sha256(archive.read_bytes()).hexdigest()}')
    return archive


if __name__ == '__main__':
    package(ROOT / 'dist')
