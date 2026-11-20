# Motor de PDF incorporado ao módulo NFS-e Nacional

Situação da versão **0.4.7**: motor, dependências e fontes são autocontidos. A
geração do DANFSe utiliza Arial regular, Arial Bold e Microsoft Sans Serif
originais, fornecidos pelo usuário em **10/11/2026** e já preparados em
`fonts/nt008/`. Não é necessário instalar fontes no Perfex ou no sistema
operacional. Os recursos Liberation da versão 0.4.4 permanecem por histórico e
licença, sem uso na geração atual.

Esta distribuição inclui TCPDF **7.0.22**, a fachada de compatibilidade, e o
motor **tc-lib-pdf 8.84.0**, com suas dependências PHP. O conjunto foi obtido dos
repositórios oficiais de Nicola Asuni / Tecnick.com e é distribuído como código
fonte sob **LGPL-3.0-or-later**. As licenças e atribuições originais acompanham
cada pacote em `packages/`; o texto da GPLv3 incorporado pela LGPL está em
`COPYING.GPL3`.

Este é um conjunto modificado para isolamento da aplicação hospedeira, e não
uma distribuição oficial do fornecedor. As alterações abaixo são datadas de
2026-11-10. Os componentes continuam substituíveis e modificáveis nos termos da
LGPL; não há limitação à engenharia reversa necessária para depurar alterações
dessas bibliotecas. Todo o código das bibliotecas, as alterações de isolamento
e os scripts de preparação acompanham o módulo.

## Alterações locais

1. O namespace `Com\Tecnick\` tornou-se `NfseNacional\PdfVendor\Com\Tecnick\`.
2. As classes globais `TCPDF` e `TCPDF_ENGINE` e as constantes `K_*`, `PDF_*` e
   `HEAD_MAGNIFICATION` receberam o prefixo `NFSE_NACIONAL_PDF_`.
3. Essas substituições mecânicas atingem código, nomes de classes em strings e
   anotações. Não alteram os algoritmos de renderização das fontes upstream.
4. `tcpdf_autoconfig.php` foi substituído por configuração local fechada. Não lê
   configurações do sistema ou do CRM, não altera `$_SERVER`, não registra
   constantes do TCPDF global e não procura instalações do Composer.
5. `bootstrap.php` registra um autoloader exclusivo para o namespace isolado.
6. `Engine.php` restringe os recursos lidos às fontes e aos arquivos deste
   módulo. A lista de hosts permitidos é vazia. Nenhum recurso é baixado ao
   instalar o módulo, abrir configurações ou gerar um DANFSe.
7. Desde a versão 0.4.5, a configuração local exige os recursos de
   `fonts/nt008/`, com Arial para títulos/rótulos e Microsoft Sans Serif para
   conteúdos. O diagnóstico e a criação do gerador exigem o pacote de fontes
   preparado; a presença dos artefatos Liberation históricos não habilita o PDF.

Arquivos de exemplo, certificados de demonstração, ferramentas de instalação e
testes do fornecedor foram excluídos. As classes necessárias aos componentes
foram mantidas inteiras. O único código upstream modificado nos arquivos de
`src/` e em `tcpdf.php` é a transformação mecânica descrita acima.

## Dependências e versão do PHP

A geração de PDF requer **PHP 8.2 ou superior**, além de `ctype`, `curl`,
`filter`, `hash`, `json`, `mbstring`, `openssl`, `pcre`, `xml` e `zlib`, conforme
as dependências declaradas pelos pacotes. Não requer Composer no servidor,
instalação global de fontes, bibliotecas PDF do Perfex, GD ou ImageMagick para
o modelo DANFSe utilizado pelo módulo.

O ponto de entrada `libraries/NfseNacional/PdfRuntime.php` permanece compatível
com a sintaxe PHP 8.1 para informar a versão necessária antes de carregar as
bibliotecas. Não há fallback para a instalação do CRM ou para TCPDF 6.x.

Na verificação de 2026-11-10, a política oficial declarou TCPDF 6.x sem suporte
de segurança e TCPDF 7.x em manutenção. TCPDF 7.0.22 incorpora a correção da
falha de caminho de máscara de imagem publicada para 7.0.19. O grafo travado
passou pelo `composer audit` sem avisos conhecidos na mesma data. Essa
verificação não substitui atualizações periódicas das dependências.

Fontes oficiais para acompanhamento:

- https://github.com/tecnickcom/TCPDF/releases/tag/7.0.22
- https://github.com/tecnickcom/TCPDF/security
- https://github.com/tecnickcom/tc-lib-pdf

## Fontes incluídas — versão 0.4.7

Os recursos de `fonts/nt008/` foram preparados a partir dos originais
`arial.ttf` (Arial regular, versão 3.00), `arialbd.ttf` (Arial Bold, versão 3.00)
e `micross.ttf` (Microsoft Sans Serif regular, versão 1.33), fornecidos pelo
usuário em 10/11/2026. A referência tipográfica é a seção 2.4 da
[NT 008 v1.02](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-008-se-cgnfse-danfse-20260714-v1-02.pdf).

Os programas completos dos originais ficam comprimidos nos arquivos `.z`,
com seus registros de autoria, marcas e licença preservados. Os hashes dos
originais e dos recursos preparados estão em `fonts/nt008/manifest.json`.
Os avisos dos arquivos fornecidos identificam:

- **Arial regular e Bold:** Typeface © The Monotype Corporation plc. Data ©
  The Monotype Corporation plc/Type Solutions Inc. 1990–1992. All Rights
  Reserved. Arial® é marca de The Monotype Corporation plc.
- **Microsoft Sans Serif:** Copyright © 1997 Microsoft Corporation. All
  rights reserved. Microsoft Sans Serif é marca de Microsoft Corporation.

As fontes não são licenciadas pela LGPL dos motores PHP nem pela SIL OFL dos
artefatos Liberation. O módulo não concede ou amplia direitos sobre os
originais fornecidos. A permissão para embedding em documentos não assegura
automaticamente redistribuição em aplicativo; consulte a
[FAQ oficial da Microsoft](https://learn.microsoft.com/en-us/typography/fonts/font-faq).

`DanfeFonts` e `PdfRuntime` exigem os recursos preparados em `fonts/nt008/` e
seu `manifest.json`. Ausência, inconsistência ou identificação inválida impede
a geração. Não há consulta a fontes do Perfex/sistema, download ou escolha de
fonte alternativa. A renderização não grava no diretório de fontes.

## Fontes históricas — versão 0.4.4

**Liberation Sans 2.1.5**, regular e negrito, sob **SIL Open Font License 1.1**,
foram usadas na versão 0.4.4. Seus TTF originais em `fonts/source/`, os arquivos
`.json`, `.z`, `.ctg.z`, licença, autoria e procedência continuam preservados
em `fonts/`. Mantêm seus nomes reais e não se apresentam como Arial ou
Microsoft Sans Serif. **Esses arquivos não são usados pelo DANFSe atual e
não constituem fallback para as fontes obrigatórias.**

Os resultados anteriores de PDFs com Liberation pertencem à versão 0.4.4.
Na versão 0.4.7, dois PDFs de ensaio sintéticos foram gerados e revisados em
A4, com uma página cada. `pdffonts` confirmou `ArialMT`, `Arial-BoldMT` e
`MicrosoftSansSerif` incorporadas, com subconjuntos e mapas Unicode. Esses
ensaios locais não representam homologação fiscal ou transmissão de nota.

## Reprodução e auditoria

`upstream.lock.json` registra versões, commits, URLs de origem, requisitos e os
hashes SHA-256 de cada arquivo antes/depois do isolamento. `MANIFEST.sha256`
registra os arquivos desta distribuição. `autoload-map.json` define as únicas
bibliotecas carregáveis pelo autoloader local.

Para reconstruir o código, prepare um diretório de desenvolvimento com o grafo
de pacotes e versões de `upstream.lock.json`, usando Composer com `--no-dev
--no-scripts`, e execute:

```sh
python build-runtime.py /caminho/do/diretorio-de-desenvolvimento
```

O script não baixa arquivos. Ele só copia as fontes, licenças e metadados das
dependências preparadas, aplica o prefixo e atualiza o registro de hashes.

Os recursos exigidos já acompanham a versão 0.4.7. Para reproduzir sua
preparação offline, execute a partir da raiz do módulo, com os três originais
no diretório de entrada e um caminho de saída ainda inexistente:

```sh
php tools/build_nt008_fonts.php /caminho/fontes-originais vendor/pdf-runtime/fonts/nt008
```

O script verifica os metadados e flags técnicos dos TTF, usa o importador
incorporado e prepara os recursos e seu manifesto sem modificar os originais.
Não baixa arquivos. Os avisos e direitos aplicáveis devem acompanhar a
distribuição autorizada; metadados técnicos não comprovam o licenciamento.

`build-fonts.php` permanece como ferramenta histórica da versão 0.4.4 para
reproduzir os artefatos Liberation; sua execução não prepara o conjunto NT 008
nem libera o PDF atual. Nenhum desses scripts é executado automaticamente
pelo módulo. Após preparar ou atualizar fontes licenciadas, repita a
conferência de integridade, incorporação, leiaute e coexistência antes de
distribuir o pacote.
