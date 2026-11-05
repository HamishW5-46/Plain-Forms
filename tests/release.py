"""Verify release contents and byte-for-byte reproducibility without third-party packages."""
from pathlib import Path
import importlib.util
import tempfile
import zipfile

root = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('plainforms_package', root / 'scripts/package.py')
packager = importlib.util.module_from_spec(spec)
spec.loader.exec_module(packager)
with tempfile.TemporaryDirectory() as directory:
    destination = Path(directory)
    archive = packager.package(destination)
    original = archive.read_bytes()
    assert packager.package(destination).read_bytes() == original
    with zipfile.ZipFile(archive) as release:
        names = release.namelist()
        assert all(name.startswith('plain-forms/') for name in names)
        assert not any(part.startswith('.') or part in ('tests', 'node_modules', 'vendor', 'docs', 'dist', 'test-results') for name in names for part in Path(name).parts)
        assert all('plain-forms/' + name in names for name in ('plain-forms.php', 'uninstall.php', 'LICENSE', 'readme.txt', 'assets/admin-app.js', 'assets/src/admin/index.jsx', 'Blocks/Form/block.json', 'languages/'))
        assert release.read('plain-forms/assets/admin-app.js') == (root / 'assets/admin-app.js').read_bytes()
print('5 release packaging checks passed.')
