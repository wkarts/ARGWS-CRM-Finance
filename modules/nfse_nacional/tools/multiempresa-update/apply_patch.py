#!/usr/bin/env python3
"""Apply this exact, optional Multiempresa 1.2.4 source correction after checking hashes.
No network, database, module activation, or approval is performed by this program.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import tempfile


def sha(data):
    return hashlib.sha256(data).hexdigest()


def inside(child, parent):
    try:
        child.relative_to(parent)
        return True
    except ValueError:
        return False


def checked_file(root, name):
    relative = Path(name)
    if relative.is_absolute() or '..' in relative.parts:
        raise ValueError('Caminho inválido no manifesto.')
    path = root
    for part in relative.parts:
        path = path / part
        if path.is_symlink():
            raise ValueError('Recusado vínculo simbólico: ' + name)
    if not path.is_file() or not inside(path.resolve(), root):
        raise ValueError('Arquivo não encontrado: ' + name)
    return path


def apply_unified(original, lines):
    old = original.decode('utf-8').splitlines(keepends=True)
    result, cursor, i = [], 0, 0
    while i < len(lines):
        match = re.fullmatch(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@\n', lines[i])
        if not match:
            raise ValueError('Hunk inválido no patch.')
        start, old_count, _, new_count = (int(match[1]), int(match[2] or 1), int(match[3]), int(match[4] or 1))
        start -= 1
        if start < cursor or start > len(old):
            raise ValueError('Posição inválida no patch.')
        result.extend(old[cursor:start])
        cursor, consumed, produced = start, 0, 0
        i += 1
        while i < len(lines) and not lines[i].startswith('@@ '):
            kind, text = lines[i][:1], lines[i][1:]
            if kind not in (' ', '+', '-'):
                raise ValueError('Linha inválida no patch.')
            if kind in (' ', '-'):
                if cursor >= len(old) or old[cursor] != text:
                    raise ValueError('O contexto do patch difere do arquivo original.')
                cursor += 1
                consumed += 1
            if kind in (' ', '+'):
                result.append(text)
                produced += 1
            i += 1
        if consumed != old_count or produced != new_count:
            raise ValueError('Tamanho de hunk incorreto.')
    result.extend(old[cursor:])
    return ''.join(result).encode('utf-8')


def atomic_write(path, data, mode, uid, gid):
    fd, temporary = tempfile.mkstemp(prefix='.pme-update-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        current = os.stat(temporary)
        if (current.st_uid, current.st_gid) != (uid, gid):
            os.chown(temporary, uid, gid)
        current = os.stat(temporary)
        if (current.st_uid, current.st_gid) != (uid, gid):
            raise OSError('Não foi possível preservar proprietário e grupo: ' + str(path))
        # chown may clear permission bits, so restore the mode after ownership.
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('module_directory', help='Pasta modules/multiempresa da instalação principal')
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--check', action='store_true', help='Somente validar (padrão)')
    mode.add_argument('--apply', action='store_true', help='Aplicar após todas as verificações')
    parser.add_argument('--backup-dir', help='Diretório novo e privado, fora da instalação web')
    args = parser.parse_args()
    supplied = Path(args.module_directory).expanduser()
    if supplied.is_symlink():
        raise ValueError('Informe a pasta física do módulo; vínculos simbólicos são recusados.')
    root = supplied.resolve(strict=True)
    bundle = Path(__file__).resolve().parent
    manifest = json.loads((bundle / 'manifest.json').read_text('utf-8'))
    patch = (bundle / manifest['patch']).read_bytes()
    if sha(patch) != manifest['patch_sha256']:
        raise ValueError('A integridade do patch não confere.')
    header = checked_file(root, 'multiempresa.php').read_text('utf-8')
    version = re.search(r'^Version:\s*(\S+)\s*$', header, re.MULTILINE)
    if not version or version[1] != manifest['base_version']:
        raise ValueError('Este patch aceita somente Multiempresa ' + manifest['base_version'] + '.')
    originals = {}
    for name, expected in {**manifest['guards'], **{n: v['before'] for n, v in manifest['files'].items()}}.items():
        value = checked_file(root, name).read_bytes()
        if sha(value) != expected:
            raise ValueError('Arquivo diferente da base verificada: ' + name + '. Nenhuma alteração aplicada.')
        originals[name] = value
    blocks, current = {}, None
    lines = patch.decode('utf-8').splitlines(keepends=True)
    i = 0
    while i < len(lines):
        if lines[i].startswith('--- a/'):
            name = lines[i][6:].rstrip('\n')
            i += 1
            if i >= len(lines) or lines[i] != '+++ b/' + name + '\n' or name in blocks:
                raise ValueError('Cabeçalho inválido no patch.')
            blocks[name] = []
            current = name
        elif current is None:
            raise ValueError('Patch sem cabeçalho.')
        else:
            blocks[current].append(lines[i])
        i += 1
    if set(blocks) != set(manifest['files']):
        raise ValueError('Os arquivos do patch não correspondem ao manifesto.')
    planned = {}
    for name, hunks in blocks.items():
        data = apply_unified(originals[name], hunks)
        if sha(data) != manifest['files'][name]['after']:
            raise ValueError('Resultado do patch diverge do manifesto: ' + name)
        planned[name] = data
    print('Base Multiempresa 1.2.4 e integridade conferidas: ' + str(len(planned)) + ' arquivos.')
    if not args.apply:
        print('Conferência concluída. Nenhum arquivo, banco ou módulo foi alterado.')
        return
    if not args.backup_dir:
        raise ValueError('--apply exige --backup-dir para uma pasta nova e privada.')
    backup = Path(args.backup_dir).expanduser().resolve()
    web_root = root.parent.parent if root.parent.name == 'modules' else root
    if inside(backup, web_root) or backup.exists():
        raise ValueError('O backup deve usar uma pasta nova, fora da instalação web.')
    for name, original in originals.items():
        if checked_file(root, name).read_bytes() != original:
            raise ValueError('Um arquivo mudou durante a conferência. Execute novamente.')
    backup.mkdir(mode=0o700, parents=True, exist_ok=False)
    metadata = {}
    for name in planned:
        saved = backup / name
        saved.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        saved.write_bytes(originals[name])
        saved.chmod(0o600)
        attributes = (root / name).stat()
        metadata[name] = (stat.S_IMODE(attributes.st_mode), attributes.st_uid, attributes.st_gid)
    written = []
    try:
        for name, data in planned.items():
            atomic_write(root / name, data, *metadata[name])
            written.append(name)
    except BaseException:
        for name in reversed(written):
            if (root / name).read_bytes() == planned[name]:
                atomic_write(root / name, originals[name], *metadata[name])
        raise
    (backup / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', 'utf-8')
    print('Correção aplicada. Originais preservados em: ' + str(backup))
    print('Use Atualizar módulo na empresa desejada; nenhuma aprovação ou ativação foi executada por este aplicador.')


if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, KeyError) as error:
        raise SystemExit('ERRO: ' + str(error))
