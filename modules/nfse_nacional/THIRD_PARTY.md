# Artefatos de terceiros

## Perfex CRM

O núcleo comercial do Perfex não está incluído neste pacote. O módulo usa suas interfaces, sua licença instalada e suas dependências originais, incluindo CodeIgniter, Bootstrap, jQuery e mecanismo de e-mail. O DANFSe possui recursos de PDF próprios e não utiliza o TCPDF do Perfex. Os módulos Asaas e Multiempresa fornecidos foram analisados como referência de integração; seus pacotes não são redistribuídos dentro deste ZIP.

## Esquemas NFS-e

Os oito arquivos XSD são cópias dos esquemas públicos nacionais obtidas de um espelho público. `schemas/provenance.json` informa origem, revisão e hashes por arquivo. Não houve comparação com o ZIP oficial, cujo download retornou HTTP 403. As URLs da documentação oficial constam em docs/API.md.

## Logomarca NFS-e

Logomarca oficial obtida na seção pública [Logos da NFS-e](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/logos-da-nfs-e), usada no documento auxiliar conforme referência da NT 008. Sua presença não significa certificação ou homologação governamental do módulo.

## Fontes e dependências de PDF

O pacote inclui TCPDF 7.0.22, fachada de compatibilidade para tc-lib-pdf 8.84.0, e as dependências necessárias sob `vendor/`. As licenças, os avisos originais, as versões, a procedência e as alterações de isolamento acompanham esses arquivos. O código-fonte PHP das bibliotecas permanece legível e separado do código do módulo. As classes e constantes são prefixadas para evitar colisões com o ambiente hospedeiro; não há carregamento do Composer ou de fontes do CRM.

### Fontes incluídas na versão 0.4.7

O DANFSe utiliza **Arial regular, Arial Bold e Microsoft Sans Serif regular**, conforme a tipografia da seção 2.4 da [NT 008 v1.02](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-008-se-cgnfse-danfse-20260714-v1-02.pdf). Os três arquivos originais foram fornecidos pelo usuário em **10/11/2026** e preparados offline por `tools/build_nt008_fonts.php`. Seus descritores `.json`, programas `.z`, mapas `.ctg.z` e `manifest.json` acompanham o pacote em `vendor/pdf-runtime/fonts/nt008/`. Não há instalação no Perfex/sistema ou download durante a geração.

| Originais fornecidos | Versão | Autoria e marcas constantes dos arquivos |
|---|---|---|
| `arial.ttf`, `arialbd.ttf` | 3.00 | Typeface © The Monotype Corporation plc. Data © The Monotype Corporation plc/Type Solutions Inc. 1990–1992. All Rights Reserved. Arial® é marca de The Monotype Corporation plc. |
| `micross.ttf` | 1.33 | Copyright © 1997 Microsoft Corporation. All rights reserved. Microsoft Sans Serif é marca de Microsoft Corporation. |

Os programas completos das fontes, incluindo os registros originais de autoria, marcas e licença, permanecem nos recursos `.z`. O manifesto registra os hashes dos originais e dos recursos preparados. Essa transformação técnica não concede direitos adicionais. A [FAQ oficial da Microsoft](https://learn.microsoft.com/en-us/typography/fonts/font-faq) distingue a incorporação em documentos da redistribuição em aplicativos: a primeira não autoriza automaticamente a segunda. Nenhuma licença de redistribuição dessas fontes é presumida ou concedida pelo módulo.

### Artefatos históricos da versão 0.4.4

Liberation Sans 2.1.5 regular/negrito permanecem fisicamente em `vendor/pdf-runtime/fonts/`, sob **SIL Open Font License 1.1**, com nomes, TTF originais, licença e procedência preservados. Foram utilizadas pela versão 0.4.4 e **não são utilizadas nem oferecidas como fallback pela versão atual**. A compatibilidade de métricas/layout com Arial mencionada pelo [projeto oficial](https://github.com/liberationfonts/liberation-fonts) não torna essas fontes idênticas às famílias prescritas na NT 008.

Veja o procedimento de preparação e os limites de validação em [DANFSE_EMBUTIDO.md](docs/DANFSE_EMBUTIDO.md).

## Catálogo de municípios

O arquivo `resources/municipios.json` contém 5.571 códigos IBGE e nomes extraídos da [tabela oficial de municípios da Receita Federal / Sped, versão 5](https://www.gov.br/sped/pt-br/assuntos/escrituracoes-digitais/e-financeira/manuais-e-documento-tecnicos/tabelas-de-codigos/municipios/@@download/file). A origem, a data de obtenção e o hash da fonte estão em `resources/municipios-source.json`. No DANFSe, o catálogo traduz apenas códigos presentes no XML e não consulta cadastros atuais do CRM. Na preparação de novas emissões, também identifica códigos por correspondência inequívoca de cidade e UF já cadastradas; não altera notas autorizadas.

O código do módulo foi produzido para esta entrega. Não há exigência de serviço pago intermediário para comunicar-se com a SEFIN Nacional.

## Catálogo nacional de serviços

`resources/servicos.json` contém códigos e descrições de tributação nacional extraídos do Anexo B v1.01 de 22/01/2026 e da tabela de novos fatos geradores da NT007. A origem governamental, a republicação utilizada, as conferências com fontes primárias e os hashes estão em `resources/servicos-source.json` e `docs/CATALOGO_SERVICOS.md`. A planilha XLSX e as dependências de teste do Bootstrap-select não são redistribuídas neste módulo.

## Ícone de navegação Font Awesome

`assets/navigation-file-lines.svg` é o ícone `file-lines`, solid, original do Font Awesome Free 6.7.2, de Fonticons, Inc., sob licença CC BY 4.0. Atribuição original preservada no SVG e licença fornecida em `assets/FONT-AWESOME-LICENSE.txt`. Fonte: https://github.com/FortAwesome/Font-Awesome/blob/6.7.2/svgs/solid/file-lines.svg. O arquivo permanece como artefato legado com sua licença preservada; a versão 0.4.2 não o utiliza na navegação nem aplica máscara CSS. Os ícones da navegação vêm do Font Awesome já carregado pelo Perfex.
