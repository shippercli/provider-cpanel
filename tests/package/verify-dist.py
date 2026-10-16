"""Build the actual Composer ZIP and inspect it independently of the checkout."""
from pathlib import Path
import subprocess
import tempfile
import zipfile

root = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='shipper-dist-') as directory:
    output = Path(directory)
    subprocess.run(['composer', 'archive', '--format=zip', '--dir='+str(output),
                    '--file=provider', '--no-interaction'], cwd=root, check=True)
    with zipfile.ZipFile(output / 'provider.zip') as archive:
        names = archive.namelist()
        for prefix in ('vendor/', 'tests/', '.github/'):
            if any(name.startswith(prefix) for name in names):
                raise RuntimeError('Development files were included in distribution: '+prefix)
        for required in ('composer.json', 'src/CpanelProvider.php', 'resources/validate-release.py'):
            if required not in names:
                raise RuntimeError('Runtime file missing from distribution: '+required)
        archive.extractall(output / 'package')
    subprocess.run(['php', str(root / 'tests/package/verify-dist.php'), str(output / 'package')],
                   cwd=output, check=True)
    result = subprocess.run(['python3', str(output / 'package/resources/validate-release.py')],
                            cwd=output, check=False, capture_output=True, text=True)
    if result.returncode != 2 or 'Usage:' not in result.stderr:
        raise RuntimeError('Extracted Python helper did not start correctly')
    print('Composer distribution verified: {} files'.format(len(names)))
