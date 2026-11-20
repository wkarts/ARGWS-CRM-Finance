# DANFSe com recursos internos — versão 0.4.7

## Funcionamento

O motor do DANFSe é autocontido: bibliotecas, dependências, geração de QR Code e fontes ficam na pasta do módulo. **A versão 0.4.7 inclui os recursos preparados de Arial regular, Arial Bold e Microsoft Sans Serif, a partir dos três arquivos originais fornecidos pelo usuário em 10/11/2026.** Os descritores e programas das fontes já acompanham o ZIP em `vendor/pdf-runtime/fonts/nt008/`.

A geração usa somente os recursos internos, sem etapa adicional de instalação de fontes. Não instala fontes no Perfex ou no sistema operacional, não executa Composer e não baixa bibliotecas ou fontes durante a emissão, o download ou o envio do documento.

Os pontos de download e envio continuam utilizando `Danfe::render($xml)`. O XML arquivado é a origem do documento; a geração não consulta os dados atuais do cliente para substituir informações de uma nota autorizada. Não há cache persistido do PDF: notas anteriores passam a usar os novos recursos no próximo download ou envio.

## Organização dos arquivos

| Caminho relativo ao módulo | Finalidade |
|---|---|
| `libraries/NfseNacional/Danfe.php` | Leiaute e preenchimento a partir do XML autorizado |
| `libraries/NfseNacional/PdfRuntime.php` | Validação dos requisitos, diagnóstico e criação do gerador interno |
| `libraries/NfseNacional/DanfeFonts.php` | Exigência e verificação das fontes originais da NT 008 |
| `tools/build_nt008_fonts.php` | Preparação offline dos três originais licenciados, durante a construção do pacote |
| `vendor/pdf-runtime/` | Bibliotecas isoladas, configuração, autoload e documentos de procedência/licença |
| `vendor/pdf-runtime/fonts/nt008/` | Recursos Arial/Microsoft Sans Serif já preparados, incluídos no pacote, com `manifest.json` e hashes dos originais e dos artefatos |
| `vendor/pdf-runtime/fonts/` e `vendor/pdf-runtime/fonts/source/` | Artefatos e TTF Liberation Sans da versão 0.4.4, preservados com licença e procedência, sem uso na geração atual |
| `assets/logo-nfse.png` | Logomarca local utilizada no DANFSe |

Os arquivos das fontes devem permanecer legíveis para o PHP. A geração não exige permissão de escrita nessa pasta e não procura fontes no sistema operacional ou no núcleo da aplicação. A pasta `vendor/` faz parte do ZIP instalável e precisa acompanhar a atualização, inclusive nas cópias privadas provisionadas pelo Multiempresa.

## Isolamento da aplicação

O motor utiliza TCPDF 7.0.22 como fachada de compatibilidade para tc-lib-pdf 8.84.0. Essa escolha mantém a API usada pelo leiaute existente e utiliza a implementação sucessora do motor. A [política de manutenção oficial](https://github.com/tecnickcom/TCPDF/security) informa que a linha 7 recebe correções críticas e de segurança; a linha 6 está sem manutenção.

As bibliotecas `Com\Tecnick` do pacote recebem prefixo próprio, e as classes e constantes globais da fachada recebem `NFSE_NACIONAL_PDF_`. A configuração interna não importa a configuração de PDF do CRM, não define os nomes globais `TCPDF`/`K_PATH_FONTS` e não modifica `$_SERVER`. O autoloader atende somente as classes do motor incluído no módulo.

As operações de leitura de recursos permitem apenas a pasta do motor e os assets do módulo. A lista de hosts remotos é vazia. A URL oficial codificada no QR Code é tratada como texto: gerar o código não realiza uma consulta ao portal.

Não é necessário desativar ou substituir o motor de faturas do Perfex. Ambos podem ser carregados na mesma requisição em qualquer ordem. A verificação local inclui uma instalação real de TCPDF 6.10.1 como biblioteca hospedeira; isso não substitui a validação da versão e das personalizações do ambiente de destino.

## Requisitos de PHP

O DANFSe interno exige **PHP 8.2 ou superior, 64 bits**, com as extensões ctype, cURL, filter, hash, JSON, mbstring, OpenSSL, PCRE, XML e zlib. Essas são dependências do ambiente PHP; as bibliotecas de PDF são distribuídas prontas no módulo.

O arquivo `PdfRuntime.php` mantém sintaxe compatível com PHP 8.1 e verifica a versão antes de carregar os arquivos do motor. Nessa versão antiga de PHP, o diagnóstico informa o requisito de atualização, e a tentativa de gerar PDF retorna erro controlado. Não há retorno silencioso ao TCPDF instalado no CRM. Para utilizar todas as funcionalidades, inclusive o anexo PDF no e-mail, execute o módulo em PHP 8.2 ou superior.

## Fontes incluídas na versão 0.4.7

A seção 2.4 da [NT 008 v1.02](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-008-se-cgnfse-danfse-20260714-v1-02.pdf) determina Arial para títulos/rótulos e Microsoft Sans Serif para conteúdo. Os seguintes originais fornecidos pelo usuário em 10/11/2026 foram usados na preparação dos recursos incluídos:

| Arquivo fornecido ao build | Família e estilo | Nome PostScript | Versão do original |
|---|---|---|---|
| `arial.ttf` | Arial regular | `ArialMT` | 3.00 |
| `arialbd.ttf` | Arial Bold | `Arial-BoldMT` | 3.00 |
| `micross.ttf` | Microsoft Sans Serif regular | `MicrosoftSansSerif` | 1.33 |

Os avisos de autoria, marcas e licença dos originais permanecem no programa completo de cada fonte, comprimido no recurso `.z`, cuja integridade é conferida pelo manifesto. As atribuições constam também em [THIRD_PARTY.md](../THIRD_PARTY.md) e em [NOTICE.md](../vendor/pdf-runtime/NOTICE.md). O módulo não concede nem amplia os direitos de uso ou redistribuição desses arquivos. A [FAQ oficial da Microsoft](https://learn.microsoft.com/en-us/typography/fonts/font-faq) distingue a incorporação em documentos da redistribuição em aplicativos.

Não são obtidas cópias de mirrors nem renomeadas famílias abertas. Os arquivos preparados devem manter a identidade original das fontes. Os metadados e flags técnicos do TTF não comprovam a licença ou a procedência dos originais fornecidos.

## Reprodução da preparação offline

Os recursos necessários já estão preparados na versão 0.4.7. O procedimento abaixo serve apenas para reconstruir o pacote a partir dos originais; não é uma etapa de instalação do usuário. No ambiente de construção, a partir da pasta do módulo, execute:

```bash
php tools/build_nt008_fonts.php /caminho/fontes-originais vendor/pdf-runtime/fonts/nt008
```

O diretório de origem deve conter os três arquivos indicados acima, e o diretório de saída não deve existir. O script confere identificação, estilo e permissões técnicas de incorporação, usa o importador incluído no módulo e prepara os descritores `.json`, programas `.z`, mapas `.ctg.z` e `manifest.json`. Não modifica os originais e não realiza downloads.

Inclua o diretório resultante no pacote autorizado do módulo e mantenha os avisos e direitos aplicáveis às fontes. Esse procedimento pertence ao build; não é executado automaticamente na instalação, nas configurações ou na geração do DANFSe. A distribuição final precisa passar novamente pela conferência dos recursos e do PDF.

## Diagnóstico e estado da geração

Com os requisitos de PHP atendidos e os recursos incluídos íntegros, o diagnóstico informa:

- `tcpdf_available = true`: o motor interno pôde ser carregado.
- `fonts_available = true` e `exact_fonts = true`: o conjunto obrigatório foi encontrado e validado dentro do módulo.
- `available = true`: o gerador está disponível com as fontes da NT 008.

Se os recursos forem removidos, alterados ou enviados de forma incompleta, o diagnóstico informa o problema e impede a geração com arquivos inválidos. Não há substituição de família nem busca em diretórios de fontes do Perfex ou do sistema operacional. O diagnóstico favorável não representa homologação fiscal integral do módulo.

## Histórico da versão 0.4.4

A versão 0.4.4 gerava PDFs com **Liberation Sans 2.1.5** regular/negrito, sob SIL Open Font License 1.1, obtidas da [release oficial](https://github.com/liberationfonts/liberation-fonts/releases/tag/2.1.5). Esse uso foi documentado como alternativa sem conformidade tipográfica exata com as famílias da NT 008.

Os TTF, recursos preparados, licença, autores e procedência continuam fisicamente em `vendor/pdf-runtime/fonts/` por histórico e preservação das atribuições. **Não são carregados pelo DANFSe atual e não funcionam como fallback.** Seus nomes originais permanecem inalterados.

Os testes históricos da versão 0.4.4 verificaram incorporação das duas variantes Liberation, mapa Unicode, acentuação, valores monetários, página única e coexistência com TCPDF do host. Esses resultados não constituem validação do PDF com Arial/Microsoft Sans Serif.

## Verificação da versão atual

Na versão 0.4.7, a verificação independente executou **19 suítes e 696 verificações** com os recursos preparados. Dois PDFs de ensaio foram gerados em A4, com uma página cada, e revisados visualmente. A inspeção por `pdffonts` confirmou `ArialMT`, `Arial-BoldMT` e `MicrosoftSansSerif` incorporadas, com subconjuntos e mapas Unicode. Os XMLs usados são sintéticos; esses resultados comprovam a geração local com as fontes fornecidas, sem constituir homologação fiscal.

Para reproduzir as verificações de documento, fontes incorporadas, leiaute e isolamento:

```bash
PHP_BIN=php PDF_TEST_OUTPUT=/tmp/nfse-nacional-teste sh tools/test.sh
```

Os cenários de isolamento contemplam hospedeiro primeiro, módulo primeiro e pacote interno incompleto. A geração também deve preservar os arquivos das fontes. A inspeção visual e a extração de texto podem utilizar Poppler no ambiente de desenvolvimento; Poppler não é requisito do CRM.

Resultados da revisão constam em [TESTES.md](TESTES.md). Os ensaios descritos acima são locais, sem transmissão fiscal ou envio SMTP externo. A implantação no portal deve ser conferida separadamente.

## Atualização e manutenção

Substitua a pasta completa do módulo pelo novo pacote, incluindo `vendor/` e os recursos de `fonts/nt008/` já fornecidos. A alteração das fontes do PDF não exige mudança de banco. Para as migrações das demais funcionalidades, siga o procedimento da versão no README. Não desinstale o módulo para fazer a atualização. Não é necessária instalação de fontes na aplicação ou no sistema operacional.

Historicamente, a migração 044 da versão 0.4.4 incluiu a escolha de tomador por fatura e sua tabela de preferências, preservando notas, numerações, certificados, tomadores e configurações existentes.

Atualizações futuras das bibliotecas e fontes devem manter suas licenças, versões fixadas, procedência e transformações de isolamento reproduzíveis. Refaça os testes de geração, fontes incorporadas e coexistência após modificar esses recursos. Não substitua os arquivos por uma cópia obtida da pasta de bibliotecas do Perfex.
