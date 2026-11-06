#!/usr/bin/env python3
"""Create a deterministic WordPress release ZIP from an explicit runtime allowlist.

Run the normal build first so compiled JavaScript/CSS assets are current.
Requires only Python's standard library.
"""

from pathlib import Path
import hashlib
import re
import zipfile

ROOT = Path(__file__).resolve().parent.parent

DIRECTORIES = (
    'Admin',
    'Blocks',
    'Rest',
    'includes',
    'languages',
)

FILES = (
    'LICENSE',
    'readme.txt',
    'plain-forms.php',
    'uninstall.php',
)

ASSET_FILES = (
    'assets/admin-app.js',
    'assets/admin.css',
    'assets/admin.js',
    'assets/frontend.css',
    'assets/frontend.js',
)

REQUIRED_FILES = (
    *FILES,
    *ASSET_FILES,
    'Blocks/Form/block.json',
    'Blocks/Form/index.js',
    'Blocks/Form/index.asset.php',
)


def package(destination: Path) -> Path:
    plugin_file = ROOT / 'plain-forms.php'

    version_match = re.search(
        r'^ \* Version: ([0-9.]+)$',
        plugin_file.read_text(encoding='utf-8'),
        re.MULTILINE,
    )

    if version_match is None:
        raise ValueError('Missing plugin version')

    plugin_version = version_match.group(1)

    for required in REQUIRED_FILES:
        path = ROOT / required
        if not path.is_file():
            raise ValueError(f'Missing release input: {required}')

    sources: list[Path] = []

    for filename in FILES:
        sources.append(ROOT / filename)

    for asset in ASSET_FILES:
        sources.append(ROOT / asset)

    for directory in DIRECTORIES:
        path = ROOT / directory

        if path.is_symlink():
            raise ValueError(f'Symlinked runtime directory: {directory}')

        if not path.exists():
            if directory == 'languages':
                continue
            raise ValueError(f'Missing runtime directory: {directory}')

        sources.extend(sorted(path.rglob('*')))

    destination.mkdir(parents=True, exist_ok=True)

    archive = destination / f'plain-forms-{plugin_version}.zip'

    with zipfile.ZipFile(
        archive,
        'w',
        compression=zipfile.ZIP_DEFLATED,
        compresslevel=9,
    ) as output:
        # Preserve declared runtime directories even when empty,
        # particularly the languages Domain Path.
        for directory in (*DIRECTORIES, 'assets'):
            info = zipfile.ZipInfo(
                f'plain-forms/{directory}/',
                date_time=(1980, 1, 1, 0, 0, 0),
            )
            info.external_attr = 0o40755 << 16 | 0x10
            info.create_system = 3
            output.writestr(info, b'')

        for source in sorted(set(sources)):
            relative = source.relative_to(ROOT)

            if source.is_symlink():
                raise ValueError(f'Symlinks are not release inputs: {relative}')

            if any(
                part.startswith('.')
                or part in ('node_modules', 'vendor', '__pycache__')
                for part in relative.parts
            ):
                raise ValueError(
                    f'Development file inside runtime directory: {relative}'
                )

            if source.is_dir():
                continue

            info = zipfile.ZipInfo(
                'plain-forms/' + relative.as_posix(),
                date_time=(1980, 1, 1, 0, 0, 0),
            )
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            info.create_system = 3

            output.writestr(
                info,
                source.read_bytes(),
                compresslevel=9,
            )

    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    print(f'{archive}: SHA256 {digest}')

    return archive


if __name__ == '__main__':
    package(ROOT / 'dist')