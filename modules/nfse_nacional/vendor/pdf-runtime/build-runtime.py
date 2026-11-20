#!/usr/bin/env python3
"""Build-time only: isolate the locked upstream PHP sources without Composer at runtime.

Usage: python build-runtime.py /path/to/composer-build-directory
The input directory must contain composer.lock and vendor/ from composer install
--no-dev --no-scripts. Output is beside this script. No download is performed.
"""
from __future__ import annotations

import hashlib
import json
import re
import sys
from pathlib import Path


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def transform(source: str) -> str:
    # PHP code, class names in strings and annotations use the same namespace.
    source = source.replace("Com\\\\Tecnick\\\\", "NfseNacional\\\\PdfVendor\\\\Com\\\\Tecnick\\\\")
    source = source.replace("Com\\Tecnick\\", "NfseNacional\\PdfVendor\\Com\\Tecnick\\")
    # Names are also replaced inside defined()/constant() calls and diagnostics.
    source = re.sub(r"\b(?:K_|PDF_)[A-Z0-9_]+\b|\bHEAD_MAGNIFICATION\b", lambda m: "NFSE_NACIONAL_PDF_" + m[0], source)
    source = re.sub(r"\bTCPDF_ENGINE\b|\bTCPDF\b", lambda m: "NFSE_NACIONAL_PDF_" + m[0], source)
    return source


def main() -> None:
    build = Path(sys.argv[1]).resolve()
    output = Path(__file__).resolve().parent
    lock = json.loads((build / "composer.lock").read_text())
    packages = lock["packages"]
    assert len(packages) == 15, "Unexpected dependency graph; review changes before rebuilding."
    report = {"format": 1, "isolation": "NfseNacional\\PdfVendor\\ / NFSE_NACIONAL_PDF_", "packages": [], "files": {}}
    autoload = {}

    for package in packages:
        name = package["name"]
        assert name.startswith("tecnickcom/"), "Review new publisher before bundling."
        upstream = build / "vendor" / name
        selected = [p for p in (upstream / "src").rglob("*") if p.is_file()] if name != "tecnickcom/tcpdf" else [upstream / "tcpdf.php"]
        selected += [upstream / candidate for candidate in ("LICENSE", "LICENSE.TXT", "VERSION", "composer.json") if (upstream / candidate).is_file()]
        package_path = Path("packages") / name.removeprefix("tecnickcom/")
        report["packages"].append({"name": name, "version": package["version"], "license": package["license"], "source": package["source"], "dist": package["dist"], "requires": package.get("require", {})})

        for prefix, relative in package.get("autoload", {}).get("psr-4", {}).items():
            autoload["NfseNacional\\PdfVendor\\" + prefix] = (package_path / relative).as_posix() + "/"

        for src in selected:
            rel = package_path / src.relative_to(upstream)
            raw = src.read_bytes()
            content = transform(raw.decode()) .encode() if src.suffix == ".php" else raw
            target = output / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
            report["files"][rel.as_posix()] = {"upstream_sha256": digest(raw), "sha256": digest(content), "modified": content != raw}

    (output / "upstream.lock.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    (output / "autoload-map.json").write_text(json.dumps(dict(sorted(autoload.items(), key=lambda p: -len(p[0]))), indent=2) + "\n")
    print(f"Bundled {len(report['files'])} source/license files from {len(packages)} packages.")


if __name__ == "__main__":
    main()
