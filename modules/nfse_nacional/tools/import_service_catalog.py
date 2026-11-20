#!/usr/bin/env python3
"""Extract the national cTribNac leaves from the Anexo B, without Excel dependencies.

Usage: python3 tools/import_service_catalog.py ANEXO_B.xlsx --nt007-text NT007.txt
The NT007 text must come from the official PDF text layer, not a manually typed list.
No network requests, formulas, macros or code from the workbook are executed.
"""
from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import posixpath
import re
import xml.etree.ElementTree as ET
import zipfile

NS = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
TEST_CODES = {'990201', '990301', '990302', '990303', '990304', '990305', '990401'}


def read_xlsx(path: Path) -> list[dict]:
    with zipfile.ZipFile(path) as archive:
        if sum(info.file_size for info in archive.infolist()) > 20 * 1024 * 1024:
            raise ValueError('Planilha maior que o limite de extração de 20 MiB.')
        book = ET.fromstring(archive.read('xl/workbook.xml'))
        sheet = next((s for s in book.findall('s:sheets/s:sheet', NS)
                      if s.attrib.get('name') == 'LISTA.SERV.NAC.'), None)
        if sheet is None:
            raise ValueError('Aba LISTA.SERV.NAC. não encontrada.')
        relations = ET.fromstring(archive.read('xl/_rels/workbook.xml.rels'))
        target = next((r.attrib['Target'] for r in relations
                       if r.attrib.get('Id') == sheet.attrib[f'{{{REL}}}id']
                       and r.attrib.get('TargetMode') != 'External'), None)
        if not target:
            raise ValueError('Relacionamento interno da aba inválido.')
        target = posixpath.normpath(posixpath.join('xl', target))
        if not target.startswith('xl/worksheets/'):
            raise ValueError('Aba fora da estrutura esperada do XLSX.')
        strings = []
        if 'xl/sharedStrings.xml' in archive.namelist():
            strings = [''.join(si.itertext()) for si in ET.fromstring(archive.read('xl/sharedStrings.xml'))]
        rows = ET.fromstring(archive.read(target)).findall('s:sheetData/s:row', NS)
        result = {}
        for row in rows:
            if row.attrib.get('r') == '1':
                continue
            values = {}
            for cell in row.findall('s:c', NS):
                key = re.sub(r'[0-9]', '', cell.attrib['r'])
                if cell.find('s:f', NS) is not None:
                    raise ValueError('Fórmula encontrada: revisar a nova planilha antes de importar.')
                value = cell.findtext('s:v', '', NS)
                if cell.attrib.get('t') == 's':
                    value = strings[int(value)]
                elif cell.attrib.get('t') == 'inlineStr':
                    value = ''.join(cell.find('s:is', NS).itertext())
                values[key] = value
            raw_code = values.get('A', '')
            if not raw_code:
                continue  # The item/subitem headings are not transmissible cTribNac leaves.
            if not re.fullmatch(r'[0-9]{5,6}', raw_code):
                raise ValueError(f'Código inválido na linha {row.attrib.get("r")}.')
            code = raw_code.zfill(6)
            parts = [values.get(k, '') for k in ('B', 'C', 'D')]
            if any(not re.fullmatch(r'[0-9]{1,2}', p) or int(p) < 1 for p in parts):
                raise ValueError(f'Hierarquia inválida para {code}.')
            if ''.join(p.zfill(2) for p in parts) != code:
                raise ValueError(f'Código diverge de item/subitem/desdobro: {code}.')
            description = values.get('E', '').strip()
            if not description or code in result:
                raise ValueError(f'Descrição vazia ou código duplicado: {code}.')
            result[code] = {'code': code, 'description': description, 'environments': ['1', '2']}
        return [result[k] for k in sorted(result)]


def read_nt007(path: Path) -> list[dict]:
    text = path.read_text(encoding='utf-8')
    # Accept a normal PDF text layer or the same layer with retrieval line prefixes.
    text = re.sub(r'^L[0-9]+(?:@P[0-9]+(?:-[0-9]+)?)?: ?', '', text, flags=re.M)
    match = re.search(r'Código\s+Descrição\s+(99\.02\.01.*?)Observação:\s*o código', text, re.S)
    if not match:
        raise ValueError('Tabela de novos fatos geradores da NT007 não encontrada.')
    found = re.findall(r'(99\.(?:02|03|04)\.[0-9]{2})\s+(.*?)(?=99\.(?:02|03|04)\.[0-9]{2}|$)', match[1], re.S)
    result = []
    seen = set()
    for formatted, description in found:
        code = formatted.replace('.', '')
        description = ' '.join(description.split())
        if code in seen or code not in TEST_CODES or not description:
            raise ValueError('Novo fato gerador duplicado ou inesperado na NT007.')
        seen.add(code)
        result.append({'code': code, 'description': description, 'environments': ['2']})
    if seen != TEST_CODES:
        raise ValueError('A tabela da NT007 deve conter exatamente os sete códigos publicados.')
    return sorted(result, key=lambda item: item['code'])


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('xlsx', type=Path)
    parser.add_argument('--nt007-text', type=Path, required=True)
    parser.add_argument('--output', type=Path, default=Path(__file__).resolve().parents[1] / 'resources/servicos.json')
    args = parser.parse_args()
    production = read_xlsx(args.xlsx)
    if len(production) != 338:
        raise SystemExit('O Anexo B v1.01 20260122 deve conter 338 folhas. Revisar versão antes de substituir o catálogo.')
    tests = read_nt007(args.nt007_text)
    if {r['code'] for r in production} & {r['code'] for r in tests}:
        raise SystemExit('Um código de testes já consta em produção. Revisar a vigência antes de importar.')
    items = sorted(production + tests, key=lambda item: item['code'])
    data = (json.dumps(items, ensure_ascii=False, indent=2) + '\n').encode('utf-8')
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_bytes(data)
    print(json.dumps({'production': len(production), 'restricted': len(items), 'output': str(args.output),
                      'sha256': hashlib.sha256(data).hexdigest(),
                      'xlsx_sha256': hashlib.sha256(args.xlsx.read_bytes()).hexdigest(),
                      'nt007_text_sha256': hashlib.sha256(args.nt007_text.read_bytes()).hexdigest()}, ensure_ascii=False))


if __name__ == '__main__':
    main()
