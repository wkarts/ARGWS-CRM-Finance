#!/usr/bin/env python3
"""Build an installable ZIP, with a single nfse_nacional/ root directory."""
from pathlib import Path
from datetime import datetime, timezone
import hashlib
import os
import subprocess
import sys
import zipfile

module = Path(__file__).resolve().parent.parent
output = Path(sys.argv[1] if len(sys.argv) > 1 else module.parent / 'nfse-nacional-module-0.8.2.zip').resolve()
if output.is_relative_to(module):
    raise SystemExit('Escolha um destino fora da pasta do módulo.')
# A release must carry the verified original fonts. Never publish a ZIP that
# asks the operator to install missing font resources in the CRM or the OS.
try:
    font_check = subprocess.run([
        os.environ.get('PHP_BIN', 'php'), '-r',
        r"define('BASEPATH', getcwd()); require $argv[1]; NfseNacional\DanfeFonts::validate();",
        str(module / 'autoload.php'),
    ], text=True, capture_output=True, check=False)
except OSError as error:
    raise SystemExit('Informe PHP_BIN para validar as fontes antes de empacotar: ' + str(error))
if font_check.returncode != 0:
    raise SystemExit('Pacote não gerado: as fontes originais internas precisam estar completas e válidas.\n'
                     + font_check.stderr.strip())
output.parent.mkdir(parents=True, exist_ok=True)
paths = sorted(p for p in module.rglob('*') if p.is_file() and not p.is_symlink()
               and not any(part in {'.git', '__pycache__', 'node_modules'} for part in p.parts))
build_time = datetime.now(timezone.utc)
# ZIP stores no timezone and has two-second precision; use one UTC timestamp per build.
build_timestamp = (build_time.year, build_time.month, build_time.day,
                   build_time.hour, build_time.minute, build_time.second // 2 * 2)
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in paths:
        item = zipfile.ZipInfo('nfse_nacional/' + path.relative_to(module).as_posix(), build_timestamp)
        item.external_attr = 0o644 << 16
        item.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(item, path.read_bytes())
with zipfile.ZipFile(output) as archive:
    assert archive.testzip() is None
print(f'{output.name}: {len(paths)} arquivos, {output.stat().st_size} bytes')
print('SHA-256:', hashlib.sha256(output.read_bytes()).hexdigest())
