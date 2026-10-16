"""Build the actual Composer ZIP and inspect it independently of the checkout."""
from pathlib import Path
import json
import shutil
import subprocess
import tempfile
import zipfile

root = Path(__file__).resolve().parents[2]
with tempfile.TemporaryDirectory(prefix='shipper-dist-') as directory:
    output = Path(directory)
    source = output / 'source'
    shutil.copytree(root, source, ignore=shutil.ignore_patterns('.git', 'vendor', 'node_modules', 'builds', '.env*', 'auth.json', '__pycache__'))
    for name in ['vendor/synthetic.php', 'nested/vendor/synthetic.php', 'node_modules/synthetic.js',
                 'nested/node_modules/synthetic.js', '.env', 'nested/.env', 'auth.json', 'nested/auth.json',
                 'tests/synthetic.php', '.github/synthetic.yml', 'builds/synthetic']:
        target = source / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(json.dumps({'http-basic': {'example.invalid': {'username': 'synthetic', 'password': 'synthetic'}}})
                          if target.name == 'auth.json' else 'synthetic-excluded-marker')
    subprocess.run(['composer', 'archive', '--format=zip', '--dir='+str(output),
                    '--file=provider', '--no-interaction'], cwd=source, check=True)
    with zipfile.ZipFile(output / 'provider.zip') as archive:
        names = archive.namelist()
        for name in names:
            if any(part in ('vendor', 'node_modules', 'tests', '.github', 'builds') for part in Path(name).parts):
                raise RuntimeError('Development files were included in distribution: '+name)
            if Path(name).name.startswith('.env') or Path(name).name in ('auth.json', 'box.phar'):
                raise RuntimeError('Local file was included in distribution: '+name)
            if not name.endswith('/') and b'synthetic-excluded-marker' in archive.read(name):
                raise RuntimeError('Excluded fixture was included in distribution: '+name)
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
