# Verificação da versão 0.8.7

Consulte [ATUALIZACAO_087.md](ATUALIZACAO_087.md) para resultados, comparação com 0.8.2/0.8.3/0.8.4 e limitações.

- `PHP_BIN=php tools/test.sh`: suítes PHP; configure `NFSE_TEST_MYSQL_PORT` apenas em MariaDB local descartável para executar os ensaios SQL.
- `php tests/sync_regression_database_test.php`: teste SQL do botão real de início/retomada, continuidade por POST, worker sem sessão, permissões, emitidas/recebidas, migração interrompida e cursor preservado.
- `node tests/sync_progress_ui_test.js`: DOM com views/JavaScript reais e rede simulada, requer `jsdom` disponível no ambiente de desenvolvimento.
- `php tests/national_service_test.php` e `php tests/distribution_client_test.php`: conferência de NSU, lote, XML, ausência e Retry-After sem conexão externa.

Os testes SQL criam/limpam bases descartáveis com nomes `nfse_test_*`; não execute os ensaios em um banco de produção.

---

# Verificação da versão 0.8.0

- `php tests/ibpt_nbs_catalog_test.php`: catálogo somente local por empresa/UF; tabela ausente, válida, vencida e futura; nenhum acesso à API.
- `php tests/ibpt_nbs_view_test.php`: pesquisa por código/descrição, preservação de NBS legados, fallback manual e HTML escapado.
- Testes de catálogo e migração existentes também executados. A validação integral do módulo requer as extensões PHP do Perfex, incluindo `mbstring`, `DOM` e `cURL`.

---

# Verificacao da versao 0.7.9

Data: **12/11/2026**. Recuperacao de `DataHoraGeracao` na distribuicao ADN e exportacao estruturada de diagnostico.

- **561 arquivos PHP** passaram na verificacao de sintaxe (`php -l`).
- **151 verificacoes** de rotas, permissoes, seguranca da exportacao, timeline da operacao, filtros, isolamento entre empresas e tratamento de falhas, sem servidor CRM.
- **24 verificacoes** unitarias de datas auxiliares, classificacao segura de erros cURL e campos permitidos no log. Estas verificacoes nao usam documentos XML reais.
- **147 verificacoes** da sequencia de migracoes, incluindo a migracao `079` sem alteracoes no banco.
- **47 verificacoes** da politica IBPT de versoes anteriores e chave manual e **10** do aviso nao bloqueante, sem alterar recursos existentes.
- O teste de armazenamento operacional progrediu ate a etapa de concorrencia. O PHP do container nao possui a extensao `mbstring`; por isso os processos filhos do teste de concorrencia nao puderam concluir. A validacao do controlador utilizou um complemento temporario para `mb_*` **fora do pacote**, sem substituir o runtime esperado do Perfex.
- O PHP de teste tambem nao possui `DOM` e `cURL`; nao foi possivel executar a suite de distribuicao com XML real nem efetuar sincronizacao autenticada em homologacao/producao. A correcao e os downloads precisam ser exercitados no ambiente PHP completo da instalacao antes de serem considerados homologados em producao.

---

# Verificação da versão 0.7.6

Data: **12/11/2026**. Permissão controlada para usar tabela IBPT anterior (API/CSV) e cadastro da chave original em arquivo sem chave.

- **16 suítes PHP selecionadas / 1.138 verificações aprovadas**: cache, importação, formatos de CSV, vigência, permissão em configurações, controller, cálculo, aviso não bloqueante, migrações, perfis, catálogo, integrações e isolamento.
- **47 verificações específicas v0.7.6** de regressão autorizada pela API, troca por CSV anterior, chave cadastrada junto ao arquivo, origem da chave e retenção da tabela escolhida mesmo vencida; verifica ausência de requisições de renovação automática durante emissões seguintes.
- **Amostra CSV enviada pelo usuário**: mais 5 verificacões, totalizando **97** no ensaio de leiaute, com os **860 NBS** reconhecidos, versão 26.2.C, vigência e hash preservados. Chave de teste sintética utilizada **somente em diretório temporário isolado**, removido no encerramento. Nenhuma tabela de teste foi implantada no CRM.
- A preferência vem **desabilitada** e requer configuração salva; POST que tente apenas enviar a preferência em uma atualização não concede acesso. Regras de UF, NBS, período futuro, chave divergente e estrutura inválida continuam bloqueando a troca de tabela.
- Limites deste ambiente: PHP CLI sem extensões `DOM`/`cURL` e Node sem `jsdom`; por isso a emissão XML/assinatura, upload HTTP multipart completo e testes dinâmicos de interface não puderam ser reexecutados. Houve verificação de sintaxe de PHP e JavaScript. Nenhuma NFS-e real foi transmitida, nem houve instalação no portal.

---

# Verificação da versão 0.7.5

Data: **12/11/2026**. Correção do vencimento IBPT e aviso configurável sem bloqueio de emissão.

- **12 suítes PHP selecionadas, 927 verificações aprovadas**: cache e renovação, importação de CSV, cálculo do rascunho, valores monetários, gestão no controller, catálogo, perfis fiscais, migração `075`, preferência habilitada/desabilitada e **renderização real da tarja em Documentos com botão Emitir NFS-e preservado**.
- **190 arquivos PHP do módulo e testes verificados com `php -l`**, sem erros sintáticos; **fontes internas do DANFSe validadas** e estrutura do ZIP conferida.
- Cenários cobertos: vencimento da tabela; cálculo com cópia expirada durante falha de atualização; consulta externa limitada a uma tentativa por hora; substituição por tabela mais atual; upload de CSV expirado coerente; ajuste por empresa do aviso; ausência de modal, botão de fechar ou confirmação; sem alteração das demais validações fiscais.
- **Limite do ambiente desta execução:** o PHP CLI disponibilizado não possui `mbstring`, `dom` ou `curl`. Os testes selecionados foram executados com um *shim de teste* externo para as funções `mb_*` (não incluído no módulo). As suítes que dependem do DOM/XML, os testes JavaScript que dependem de `jsdom` e a transmissão real em SEFIN não puderam ser homologados nesta sessão. O pacote de produção **não inclui o shim** e pressupõe as extensões PHP exigidas pelo próprio Perfex/módulo.
- O portal CRM em execução **não foi atualizado por esta verificação**; a instalação fica a cargo do atualizador do Perfex ou do fluxo de publicação existente. Nenhuma NFS-e real foi emitida ou transmitida.

Execução integral recomendada antes de publicar em produção: `PHP_BIN=/caminho/php sh tools/test.sh`, seguida dos testes JS e verificação de emissão em homologação com ambiente PHP completo.

---

# Verificação da versão 0.7.4

Data: **11/11/2026**. Compatibilidade com leiautes alternativos de importação IBPT.

| Camada | Resultado | Escopo |
|---|---:|---|
| Runner PHP completo | **50 suítes / 3.575 verificações; saída 0, sem warnings** | Regressão de parser, upload, cache, fluxos fiscais e PDF |
| Leiautes de importação | **92** | Cabeçalhos/aliases, delimitadores, codificação, UF, chave, tipos, limites e hash original |
| Leitura do anexo em teste isolado | **97** | Cinco verificações adicionais: 860 NBS, versão26.2.C e vigência20/10/2026–30/11/2026; arquivo original preservado |
| Upload HTTP multipart real | **118** | Inclui 30 novos cenários de leiaute alternativo, chave complementar, conflito deUF/chave e preservação do cache |
| Interface de gerenciamento | **95** | Inclui 22 novos cenários para campo opcional, envio seletivo, limpeza e respostas tardias |
| Regressão UI IBPT/ISSQN | **49** | Cálculo e dados fiscais existentes preservados |
| Views integradas | **47** | Formulários e navegação existentes, incluindo configurações, faturas e documentos |

A chave usada no teste positivo do anexo é explicitamente sintética (`TESTELOCAL074`), exclusivamente em cache temporário isolado, removido ao fim do teste. Ela não representa a chave real da versão26.2.C. O arquivo anexado não possui coluna de chave e não é distribuído no módulo. Nenhuma tabela com chave sintética é instalada no portal.

A importação completa exige a chave original no CSV ou no campo opcional. Na ausência dela, o servidor responde com mensagem específica e mantém integralmente a tabela anterior. NCM e LC116 não são usados como substitutos de NBS.

Execução: `PHP_BIN=/caminho/php sh tools/test.sh`; para análise adicional de um arquivo local, `IBPT_LAYOUT_SAMPLE=/caminho/arquivo.csv PHP_BIN=/caminho/php /caminho/php tests/ibpt_import_layout_test.php`. Os ensaios não emitem notas, não enviam e-mails e não modificam cadastros reais.

---

# Verificação da versão 0.7.3

Data: **11/11/2026**. Importação manual do CSV IBPT na configuração da empresa emitente.

| Camada | Resultado | Escopo |
|---|---:|---|
| Runner PHP completo | **49 suítes; saída 0, sem warnings** | Regressão fiscal, parser, cache, upload e PDF com fontes originais |
| Importação IBPT | **97** | UF no nome original, codificação, limites, vigência, metadados, gravação atômica e preservação da cópia anterior |
| Upload HTTP multipart real | **88** | Controller/resolvedor/parser/store/log reais, autenticação e CSRF simulados, upload genuíno, isolamento e rejeições |
| Guia Tabela IBPT | **73** | Inclui 36 novos cenários de envio, formulário preservado, CSRF, arquivo inválido, contexto alterado e respostas tardias |
| Regressão UI IBPT/ISSQN | **49** | Cálculo e informações fiscais existentes preservados |

O ensaio de upload inicia um servidor PHP em loopback com `curl_init` desabilitado no servidor. A importação aceita arquivos sintéticos vigentes sem consulta à fonte externa. A sessão, o modelo de configurações e o middleware CSRF são fixtures explícitos; o controller, o resolvedor de UF, o parser e o armazenamento são os componentes de produção. Nenhuma tabela sintética é instalada no portal.

Execução: `PHP_BIN=/caminho/php sh tools/test.sh` e `PHP_BIN=/caminho/php NODE_PATH=/caminho/node_modules node tests/ibpt_management_ui_test.js`.

---

# Verificação da versão 0.7.2

Data: **11/11/2026**. Gestão da tabela IBPT local por UF do emitente, vigência visível e atualização manual/automática.

| Camada | Resultado | Escopo |
|---|---:|---|
| Runner PHP completo | **47 suítes / 3.266 verificações; saída 0** | Regressão das funções fiscais, formulários, atualização, cache e PDF; sem warnings |
| Motor IBPT | **243** | Reutilização até o fim da vigência, ausência de download diário, leitura durante atualização concorrente e contrato de NBS existente |
| Gestão local da tabela | **56** | Status sem rede/gravação, atualização sem NBS, intervalos automático/manual, fonte regressiva, dados divergentes e isolamento |
| Controller da atualização | **43** | Método, acesso, proteção CSRF habilitada, UF salva, rejeição de UF adulterada e atualização sem salvar parâmetros |
| Guia Tabela IBPT | **37** | Metadados, datas brasileiras, abertura sem download, POST mínimo, preservação de campos, respostas tardias e espera sem nova consulta |
| Regressão UI IBPT/ISSQN | **49** | Cálculo, perfis, consulta por rascunho, respostas assíncronas e valores autorizados preservados |
| Regressão das views | **47** | Renderização PHP, formulários, navegação de emissão, fontes de ícones e ações existentes |

Os testes do motor controlam relógio e transporte para reproduzir vencimento, intervalo de uma hora após falhas e um minuto entre atualizações manuais. Os testes de controller usam o controller real e o resolvedor real de UF; a validação efetiva do token pelo middleware do Perfex depende do ambiente integrado. Nenhuma transmissão fiscal ou envio de e-mail real faz parte desses ensaios.

Execução adicional de UI: `PHP_BIN=/caminho/php NODE_PATH=/caminho/node_modules node tests/ibpt_management_ui_test.js`.

---

# Verificação da versão 0.7.1

Data: **11/11/2026**. Integração IBPT por UF/NBS, informação complementar com fonte/chave e destaque do ISSQN.

| Camada | Resultado | Escopo |
|---|---:|---|
| Runner PHP completo | **45 suítes / 3.164 verificações; saída 0** | Regressão fiscal, formulários, recuperação, acervo nacional, PDF e integrações existentes; sem warnings |
| Tabela IBPT | **241** | CSV real/fixtures, encoding, NBS exato, três esferas, validade, cache privado, limites, concorrência, isolamento e logs |
| Aritmética | **85** | Centavos exatos, alíquotas até quatro casas, descontos, zero estadual, meio centavo e limite monetário |
| Ciclo IBPT do rascunho | **55** | UF do emitente, competência versus emissão, snapshot imutável, mudanças de NBS/UF/valores, erros e herança |
| DPS e PDF com IBPT | **86** | Assinatura e XSD nos dois ambientes, fonte/chave, ramo exclusivo, ISSQN local versus autorizado e fontes originais |
| Resumo e recuperação | **37** | Resumo auxiliar não rebaixa autorização; consulta DPS/chave e reenvio preservam XML assinado e IBPT original sem nova consulta |
| Preparação e persistência dos perfis | **65** | Inclui 12 verificações novas do Model/Service reais sobre IBPT, adulteração do POST, base revisada e falhas da fonte |
| UI específica IBPT/ISSQN | **49** | Views PHP e JS reais, herança, cálculo local exato, respostas atrasadas, fonte/vigência, mensagens, escaping e preservação do ISSQN oficial |

O restante das suítes de interface cobre navegação, pacote mensal, CNPJ/CEP, prévia, cadastros e tomadores. Nenhum CSS global ou registro de menu foi modificado nesta versão.

O PDF sintético foi renderizado e inspecionado: A4, uma página, texto completo, chave de teste, Simples com quatro casas e fontes Arial, Arial Bold e Microsoft Sans Serif incorporadas. Nenhuma autorização fiscal foi simulada como documento real.

A consulta pública à fonte de BA confirmou o CSV e seus 860 NBS, mas retornou versão 26.2.B com vigência encerrada em 31/10/2026. O teste não trata esse arquivo como vigente em 11/11/2026. Não houve transmissão fiscal, cancelamento ou e-mail real.

Execução: `PHP_BIN=/caminho/php sh tools/test.sh`. Para a UI nova: `PHP_BIN=/caminho/php NODE_PATH=/caminho/node_modules node tests/ibpt_ui_test.js`.

Consulte [IBPT_ISSQN.md](IBPT_ISSQN.md) para parametrização, comportamento em indisponibilidade e fontes oficiais.

---

# Verificação da versão 0.7.0

Data: **10/11/2026**. Recuperação de transmissão, distribuição nacional, eventos fiscais, substituição e pacote contábil, incluindo o endereço do estabelecimento da versão 0.5.0.

## Resultado local consolidado

**40 suítes PHP, 2.647 verificações aprovadas, saída 0 e nenhum warning/notice/deprecation.** Lint dos 166 arquivos PHP do módulo e seus testes (excluindo dependências de terceiros) também concluído sem erros.

**363 verificações JavaScript/DOM aprovadas:** transporte contábil 44, navegação 166, views 47, prévia 10, endereço 24, consultas cadastrais 17 e tomadores/faturas 55. Incluem os tempos limite da resposta e do corpo do ZIP, atualização de CSRF e liberação da fila sem repetição automática.

| Camada | Verificações | Escopo |
| --- | ---: | --- |
| Serviço de emissão/recuperação | 106 | Resposta perdida após aceite, DPS → chave → XML, chave manual, correlação, identidade original e ausência de outro POST |
| Controller de prévia e novos fluxos | 108 | Método, permissões, CSRF, isolamento, PDF real e bloqueio antes de acessar dados/certificado/API |
| Distribuição ADN | 136 | NSU, limites, documento/evento, mTLS, ambiente, 404 inconclusivo, fim de acervo e espera |
| Acervo nacional | 46 | Transação, cursor, papéis, XML cifrado, eventos antes da nota e isolamento |
| Pacotes mensais | 87 | Competência, deduplicação, ZIP, composição alterada e envio idempotente |
| Destinatários e envio nativo | 96 | IDs da empresa, controles, SMTP simulado, anexos intactos, ZIP truncado e limpeza do motor nativo |
| Cliente de eventos | 126 | Assinatura e XSD, tipos, pedidos/respostas, autor, ambiente e confirmação |
| Ciclo dos eventos | 80 | Persistência antes de POST, recuperação por GET, reenvio idêntico e prova preservada após falha local |
| Substituição/migração | 91 | Revisões, mesma fatura, original imutável, E0058/E0060/E0061, concorrência simulada e migração retomável |
| Sequência de atualização do Perfex | 138 | Catálogo contínuo 020–070, pontos de partida anteriores e 35 migrações intermediárias sem alterações de esquema |
| Views nacionais / contábeis / eventos | 75 / 52 / 55 | Componentes nativos, permissões, CSRF, escaping, estados e confirmações |
| PDF e motor incorporado | 48 / 17 | PDF real, fontes originais e isolamento das dependências do CRM |

As demais suítes de cadastros, A1, logs, faturas, perfis, catálogo, endereço e ambientes integram o mesmo runner. O teste de fronteira contábil não inicializa SMTP externo; os ensaios fiscais usam certificado sintético e transporte controlado. A assinatura, os parsers, os XSDs e as regras de aplicação exercitados são reais. As fixtures de banco e concorrência não substituem ensaio de carga com MySQL e múltiplos processos reais.

Foram reproduzidos e corrigidos: reenvio de substituição sem revalidar permissão/conflito fiscal; pedido de evento íntegro vinculado à nota errada; perda indevida do estado registrado após falha de projeção; normalização silenciosa de controles em e-mail; ZIP truncado aceito para envio. O download contábil também recebe tempo limite e libera a fila de interface se a resposta ou o corpo do arquivo não terminarem.

Execução: `PHP_BIN=/caminho/php sh tools/test.sh`. Os testes de interface usam jsdom no ambiente de desenvolvimento, sem acrescentar dependências ao módulo instalado. Para o fluxo contábil: `NODE_PATH=/caminho/node_modules node tests/accounting_ui_test.js`.

**Limite:** nenhum resultado acima representa autorização fiscal real, homologação de A1/ICP-Brasil contra SEFIN/ADN, cancelamento real ou entrega de e-mail. A validação autenticada depende de emitente válido, certificado disponível, habilitação e documentos do ambiente selecionado. Consulte [OPERACOES_070.md](OPERACOES_070.md) para alcance e restrições.

---

# Verificação da versão 0.5.0

Data: **10/11/2026**. Endereço completo do estabelecimento e conferência dos apontamentos de produção restrita e produção.

| Camada | Resultado | Escopo |
|---|---:|---|
| Runner PHP completo | **29 suítes / 1.542 verificações aprovadas; saída 0** | Sem warnings; regressão fiscal, PDF, logs, cadastros e formulários |
| Endereço do estabelecimento | **42/42** | Persistência no snapshot, atualização do rascunho sem DPS, preservação após reserva, prévia e ausência de prest/end na DPS do emitente |
| Reaproveitamento cadastral | **30/30** | Endereço nativo importado como conjunto, sem misturas com endereço fiscal local e sem vazamento entre empresas |
| Configurações por HTTP multipart | **40/40** | Endereço com A1, gravação parcial, validação antes de persistir, manutenção do certificado e isolamento |
| Matriz de ambientes | **64/64** | URLs e métodos de emissão, DPS, NFS-e, eventos e convênio em ambos os ambientes; bloqueio de divergência tpAmb antes de POST |
| Interface do endereço | **24/24** | View real, oito campos, consulta CNPJ/CEP, dados retornados, edições simultâneas, UF válida, formulário nativo e ambiente explícito |
| Regressão da consulta cadastral no DOM | **17/17** | Preenchimento de emitente e tomador, respostas obsoletas e preservação de alterações |

Os ensaios PHP de API usam transporte simulado e certificado sintético. O teste HTTP de gravação utiliza servidor loopback, controller/model reais e persistência de fixture. As verificações DOM usam a view real e respostas controladas. Nenhuma transmissão fiscal ou mensagem foi enviada.

As bases foram comparadas com a página oficial de APIs, atualizada em 20/08/2026. A FAQ v1.3 de 06/11/2026 confirma a origem oficial do endereço do emitente no documento autorizado. Isso não substitui homologação autenticada da SEFIN/ADN com A1 real; detalhes e fontes em [ESTABELECIMENTO_AMBIENTES.md](ESTABELECIMENTO_AMBIENTES.md).

Execução: `PHP_BIN=/caminho/php sh tools/test.sh`. Para o teste DOM do endereço: `PHP_BIN=/caminho/php NODE_PATH=/caminho/node_modules node tests/provider_address_ui_test.js` (jsdom instalado no ambiente de desenvolvimento).

---

# Verificação da versão 0.4.9

Data: **10/11/2026**. Pré-visualização do DANFSe e consulta da situação fiscal, preservando o layout e as fontes originais do módulo.

## Resultado local

| Camada | Resultado | Escopo |
|---|---:|---|
| Runner PHP completo | **27 suítes / 1.423 verificações aprovadas** | Saída 0, sem warnings; suites anteriores e cinco novas |
| Eventos fiscais | **93/93** | Cancelamentos, pedido/indeferimento, correlação, envelope simples/duplo, fallback GET 404/405, limites e logs |
| Prévia PDF | **43/43** | Oito situações, rascunho incompleto, nenhum identificador artificial, carimbos e fontes incorporadas |
| Serviço de prévia/situação | **67/67** | Sem certificado/rede/reserva/escrita na leitura, evidência de cancelamento cifrada, falha preserva estado, envio bloqueado |
| Controller de prévia | **55/55** | GET, permissões nativas, empresa, PDF inline, downloads, cache, logs e zero alterações do documento |
| Views de prévia | **71/71** | Estados, dados escapados, botões, filtros, download histórico e permissões |
| Interface DOM específica | **10/10** | Abrir prévia não dispara mutação, atualização de estado, escape e isolamento |
| Interface DOM existente | **47/47** | Regressão de views, formulário, consulta, A1, tomador, faturas e ícones |

A soma PHP anterior (1.094) mais 93 + 43 + 67 + 55 + 71 resulta em **1.423**. As 57 verificações DOM são adicionais. O teste de serviço usa renderizador/SMTP simulados explicitamente; os testes de controller, documento e prévia exercitam o PDF real. O parser e o transporte fiscal são testados com respostas controladas.

## Revisão dos PDFs

Oito documentos sintéticos gerados e renderizados em imagem para conferir rascunho, não transmitida, corrigida após tentativa, rejeitada, processando, incerta, autorizada e cancelada. Uma página A4 em cada PDF; `pdffonts` confirmou Arial, Arial Bold e Microsoft Sans Serif incorporadas. Cabeçalho da nota cancelada em homologação conserva a indicação **SEM VALIDADE JURÍDICA**. Nenhum dado real foi usado nos exemplos.

## Limites

Não houve emissão fiscal, solicitação de cancelamento ou envio real de e-mail. A consulta real de eventos com A1 válido ainda exige uma nota existente no ambiente correspondente; os testes não constituem homologação fiscal. O módulo lê eventos efetivados, sem implementar a solicitação de cancelamento. A consulta da situação é manual; a prévia usa a última evidência confirmada.

---

# Verificação da versão 0.4.8

Data: **10/11/2026**. Logs operacionais isolados por empresa e correção do diagnóstico da consulta municipal. Os ensaios HTTP fiscais usam transporte simulado; nenhuma emissão nacional ou mensagem real foi enviada.

## Resultado local

| Camada | Resultado | Escopo |
|---|---:|---|
| Conjunto PHP | **22 suítes / 1.094 verificações aprovadas** | Runner completo, com reaplicação direcionada após os últimos ajustes de mensagens/status |
| API nacional | **72/72** | Origem ADN, envelope municipal, respostas 404/HTML/vazias, XML nas mensagens e observador seguro |
| Fluxo fiscal | **55/55** | Consulta antes do reenvio e bloqueio de novo POST após 404 inconclusivo |
| Armazenamento de logs | **181/181** | Sanitização, escopo autenticado, revalidação na leitura, rotação, retenção, concorrência e falha aberta |
| Controller de logs | **117/117** | Permissões, filtros, IDs, listagem, detalhe, exportação e correlação |
| Views de logs | **50/50** | Escape, paginação, datas, estados e contexto das URLs |
| Integração nativa | **95/95** | Menu Documentos/Logs, ausência de Configurações no menu fiscal e gates de acesso |
| Upload A1 por HTTP multipart | **33/33** | Preservado: validação, persistência, cifragem, isolamento e falhas com certificado sintético |
| Documento/PDF/anexos e isolamento | **48/48 + 17/17** | Mantidos os gates com fontes originais internas |
| Consulta cadastral no DOM | **17/17** | Preenchimento e preservação das edições do emitente/tomador |
| Referência e link no DOM | **8/8** | Link permitido, ausência de permissão, origem externa recusada e texto escapado |

A soma PHP é 23 + 72 + 55 + 24 + 19 + 42 + 33 + 95 + 17 + 47 + 27 + 53 + 43 + 30 + 8 + 50 + 43 + 181 + 50 + 117 + 48 + 17 = **1.094**. Os 25 checks DOM são adicionais. O teste concorrente gravou e recuperou 80 eventos de quatro processos sem perda ou mistura de empresas.

## Limites da conclusão

O 404 informado pelo usuário não comprova a causa remota. A lista oficial de APIs mantém parâmetros municipais no ADN; o módulo preserva esse endereço e registra a origem e as mensagens disponíveis. A classificação inconclusiva evita diagnosticar ausência de convênio sem evidência.

Na inspeção autenticada anterior à implantação, a consulta da empresa ativa parou na validação local: **“Configure um CPF/CNPJ válido para a empresa emissora.”** Essa tentativa não alcançou o serviço nacional. O teste real do retorno municipal depende de cadastro e certificado válidos no ambiente selecionado. O histórico operacional só existe a partir da instalação da versão que o registra.

Nenhuma transmissão fiscal, envio de e-mail real ou mudança de tributação foi necessária para esses testes. A ponte Multiempresa de certificados não foi executada no runner sem suas dependências externas; os gates de escopo/certificado existentes e a derivação autenticada do logger foram preservados.

---

# Verificação da versão 0.4.7

Data: **10/11/2026**. Recursos originais Arial regular, Arial Bold e Microsoft Sans Serif fornecidos pelo usuário foram preparados dentro do módulo. A consulta do CNPJ do emitente também foi corrigida após reprodução autenticada do defeito no portal.

## Resultado local consolidado

| Camada | Resultado | Escopo |
|---|---:|---|
| Runner PHP completo | **19 suítes / 696 verificações aprovadas; saída 0** | Inclui geração PDF real, isolamento, consultas, formulários A1, perfis e fluxo fiscal simulado |
| Documento, PDF e anexos de e-mail | **48/48** | XML e serviços de e-mail sintéticos; nenhum SMTP externo |
| Isolamento do gerador PDF | **17/17** | Hospedeiro primeiro, módulo primeiro e pacote interno deliberadamente incompleto |
| Consulta cadastral PHP | **50/50** | Retornos controlados, IM explícita, zeros à esquerda, rejeição de IM inválida e separação da inscrição estadual |
| Consulta no DOM | **17/17** | Views reais; atualização do emitente herdado, preservação de edições em curso, troca de CNPJ e ausência de IM |
| Fontes nos dois PDFs | **ArialMT, Arial-BoldMT e MicrosoftSansSerif** | `pdffonts`: incorporadas, subconjuntos e Unicode nos três casos; sem Liberation/FreeSans |
| Revisão visual | **2 PDFs A4, uma página cada** | Modelo sintético e descrição de cerca de 2.000 caracteres, sem sobreposição de blocos |

Os 19 conjuntos somam 23 + 39 + 41 + 24 + 19 + 42 + 33 + 92 + 17 + 47 + 27 + 53 + 43 + 30 + 8 + 50 + 43 + 48 + 17 = **696**. Os 17 cenários DOM são adicionais. O arquivo de demonstração incluído em `docs/examples/danfse-nt008-teste.pdf` usa exclusivamente dados fictícios e não possui validade jurídica.

## Consulta do emitente — causa e correção

Na versão 0.4.6, a consulta do CNPJ público `00.000.000/0001-91` respondia com sucesso, mas a tela mantinha a razão social e o IBGE herdados da empresa. A causa era a regra de preencher somente campos vazios, reproduzida no portal e no DOM.

Na versão 0.4.7, consultar o emitente atualiza os campos expressamente retornados: razão social, município/IBGE, e-mail, telefone e inscrição municipal quando disponível. Edições feitas enquanto a requisição está em andamento são preservadas, e uma resposta para CNPJ já alterado é descartada. O preenchimento somente de vazios permanece nos tomadores. IM não é inferida a partir de inscrição estadual, CNAE ou CNPJ; o retorno sem IM apresenta orientação objetiva de conferência.

## Fontes e empacotamento

Os programas das três fontes anexadas foram convertidos offline e conservam os bytes originais comprimidos, com identidade e hashes verificados por `DanfeFonts`. A renderização utiliza apenas `vendor/pdf-runtime/fonts/nt008/`, sem instalar fontes na aplicação ou no sistema operacional e sem gravar ou baixar recursos durante a geração.

O empacotador valida o conjunto antes de criar um ZIP. Os testes negativos de ausência e integridade continuam ativos. Os ensaios descritos não representam uma transmissão nacional ou homologação tributária; nenhuma nota fiscal ou mensagem real foi emitida.

# Histórico — verificação da versão 0.4.6

Data: **10/11/2026**. Hotfix motivado por inspeção autenticada da versão 0.4.5 no portal. As consultas públicas de CNPJ/CEP foram executadas no formulário de um novo tomador, sem gravar o cadastro. Nenhuma transmissão fiscal ou mensagem foi enviada.

## Defeito reproduzido e corrigido

Ao selecionar a seção nativa **Documentos Fiscais → NFS-e Nacional**, o Perfex montou `admin/settings?group=settings_group`, a partir do basename da view cadastrada. A página tinha `form#settings-form`, multipart e o wrapper fiscal correto, mas nenhum script do módulo. O clique em Consultar permaneceu inerte e o salvamento não passava pela ponte fiscal.

O registro agora usa a view `nfse_nacional/nfse_nacional`. O grupo anterior `settings_group` é reconhecido somente na rota nativa de Configurações. A fixture foi corrigida para exigir os hooks reais de CSS/JS, em vez de inserir o script diretamente e mascarar essa classe de defeito.

## Resultados desta revisão

| Camada | Resultado | Limite |
|---|---:|---|
| Runner PHP consolidado inicial, sem depender das fontes | **16 suítes / 604 verificações aprovadas** | Banco, sessão e middleware do CRM simulados quando necessário |
| Hooks e carregamento nativo, após o ajuste final | **92/92** | Inclui grupo canônico, 10 casos de redirecionamento antigo e ausência de assets nas telas não fiscais |
| Consulta cadastral | **41/41** | Normalização, cache, orçamento e alternância dos serviços com transporte controlado |
| Transporte de interface | **43/43** | Permissões, métodos, contexto, CSRF e respostas |
| Upload A1 por HTTP multipart | **33/33** | Controller/model/Certificate/Crypto reais; banco e sessão simulados |
| Consulta CNPJ/CEP no DOM | **14/14** | Views e hooks reais, formulário nativo e CSRF |
| Componentes do cliente no DOM | **12/12** | Pesquisa/seleção, abas, clone, herança e preferência de fatura |
| Disponibilidade do DANFSe na guia do cliente | **8/8** | Diagnóstico simulado: disponível/pendente e uma única chamada por renderização |
| Parser de NFS-e | **20 verificações aprovadas** | Antes do gate de geração PDF; suíte de documento não concluída |
| Geração real do DANFSe | **Pendente** | Ausência de Arial regular/negrito e Microsoft Sans Serif originais |

O consolidado inicial incluiu 82 verificações de hooks. Após os ajustes finais, essa suíte foi repetida com **92/92** e a nova suíte de disponibilidade do DANFSe passou **8/8** separadamente. São **622 verificações distintas** nas 17 suítes completas: 604 iniciais + 10 casos de redirecionamento + 8 de disponibilidade. As verificações DOM são independentes. O consolidado completo não foi repetido depois desses dois testes focados. O runner termina com código **2**, pois `document_test` e `pdf_runtime_test` exigem as fontes originais. Não há fallback tipográfico.

## A1 com envio real de arquivo

`tests/settings_upload_test.php` inicia uma fixture HTTP local e usa multipart real: `CURLStringFile → $_FILES / is_uploaded_file → controller settings → Certificate → model saveSettings → Crypto`.

Foram verificados: importação com prioridade desmarcada, senha vazia válida, arquivo e senha cifrados, preservação ao salvar sem arquivo, recusa de senha incorreta/arquivo inválido/titular divergente, isolamento entre prefixos de empresa, erro de persistência, permissão e CSRF. Falhas preservam o A1 anterior. A fixture nunca recebe certificado real.

## Evidências observadas no portal instalado

- O menu **NFSE → Documentos**, o ícone principal e a guia fiscal do cliente estavam presentes com estilos nativos.
- A guia **Tomadores** abriu o modal e o perfil de emissão; os formulários estavam completos. A grande quantidade de opções do catálogo pode truncar uma árvore de acessibilidade, mas não interrompeu a view.
- Consulta do CNPJ público `00.000.000/0001-91`: retornou **BANCO DO BRASIL SA**, endereço e município `5300108`. O cadastro foi cancelado.
- Consulta do CEP público `01001000`: retornou **Praça da Sé**, bairro **Sé** e município `3550308`. O cadastro foi cancelado.
- Ao desmarcar a herança do código nacional, a busca por **programação** filtrou cinco opções no seletor nativo pesquisável.
- A grade de faturas apresentou **Emitir NFS-e** por registro; a visualização da fatura carregou o painel com seleção de tomador e ações de rascunho/emissão. Nenhuma dessas ações de gravação/transmissão foi executada.
- Nas configurações, o CPF/CNPJ do emitente estava vazio e não havia certificado local disponível. O teste de importação com um A1 real ainda depende do arquivo/senha do emitente e do cadastro correto da empresa.

## Implantação e nova conferência no portal

A atualização inicial **0.4.6** foi enviada pelo gerenciador nativo de módulos e a migração sem alteração de esquema foi concluída. Tanto a lista de módulos quanto o diagnóstico em execução mostraram **0.4.6**. O link nativo abriu `admin/settings?group=nfse_nacional` e, após o carregamento completo, CSS e JavaScript fiscais estavam presentes.

A consulta de CNPJ nas Configurações passou a apresentar **Dados localizados. Os campos já preenchidos foram preservados.** O documento público usado na consulta foi removido do formulário e a página foi recarregada sem salvar os dados consultados.

O clique em **Salvar Configurações**, com os dados originalmente carregados, foi bloqueado pela validação do campo obrigatório **CPF/CNPJ do emitente**, que está vazio. Portanto, não há afirmação de gravação efetiva de configuração ou de A1 real no banco do portal nesta revisão. O fluxo multipart completo foi verificado na fixture isolada descrita acima.

A conferência do endereço anterior `group=settings_group` identificou 404 no núcleo após a troca de basename. O pacote final inclui redirecionamento de leitura GET/HEAD para o grupo canônico, antes da resolução nativa da view, sem repetir POSTs ou ampliar permissões. O diagnóstico de fontes continua pendente.

# Histórico — verificação da versão 0.4.5

Data: **10/11/2026**. Testes locais com PHP 8.2.32, OpenSSL Library/CLI 3.5.7 e dados sintéticos. Não houve instalação desta versão no portal, alteração de fatura real, transmissão nacional ou envio de e-mail.

## Resultado atual

| Camada | Resultado | Escopo |
|---|---:|---|
| Suítes PHP completas, sem depender das fontes originais | **14 suítes / 525 verificações aprovadas** | Integração, SDK, serviço, dados do CRM, resolução/importação A1, hooks, fatura, catálogo, perfis, fontes e transporte |
| Parser de NFS-e | **20 verificações aprovadas** | Parte inicial de document_test, antes da tentativa de gerar PDF |
| Interface DOM geral | **47/47** | Formulários nativos, catálogo, CSRF, ações e disponibilidade dos controles de DANFSe/envio |
| Interface DOM de cliente, tomador, perfil e fatura | **55/55** | Herança, edição, clone, seleção, A1 sempre acessível e isolamento |
| Sintaxe PHP e JavaScript | **438 arquivos PHP e JS aprovados** | Inclui o motor distribuído, novas classes, views e migração |
| Geração real e coexistência do PDF | **Pendente** | Faltam os originais licenciados Arial regular/negrito e Microsoft Sans Serif |

As 14 suítes completas somam: integração (23), SDK (39), serviço (41), dados do CRM (24), resolvedor A1 (19), PKCS#12 (42), hooks (77), contexto da fatura (17), preferência de tomador por fatura (47), catálogo (27), padrões de serviço (53), perfil de emissão (43), pacote de fontes (30) e transporte de interface (43).

O runner `tools/test.sh` terminou deliberadamente com **saída 2**, informando a pendência de `document_test` e `pdf_runtime_test`. Os 20 checks do parser não significam conclusão da suíte de documento. A homologação de PDF não é declarada aprovada.

## Perfis, herança e clonagem

Foram exercitados model e serviço reais com banco simulado: precedência empresa → cliente → tomador; zero e vazio opcional explícitos; mapa canônico esparso; leitura de legado sem gravação; edição parcial preservando nome/identificação; perfil completo copiado ao preparar e trocar o tomador; revisão manual preservada ao salvar o mesmo tomador; valores financeiros e dados do emitente fora dos overrides.

Nas views PHP reais com jQuery/Bootstrap, Clonar abre cópia editável sem requisição de gravação; Salvar usa criação sem ID do original. Cancelar e Escape não persistem. Reabrir o original ou Novo não conserva dados residuais da cópia. Perfil, fatura e tomador continuam limitados ao cliente/empresa autorizados. A importação A1 permanece habilitada com prioridade desmarcada, aceita senha vazia para validação no servidor e limpa credenciais do formulário após sucesso.

## Leitor A1

As **42 verificações** usam chaves/certificados sintéticos, exportados em PKCS#12 real. Cobrem AES-128/192/256-PBES2, 3DES, RC2-40/128, AES-256/PBMAC1-PBKDF2, Unicode, senha longa/vazia, senha incorreta, adulteração, identidade divergente, ambiguidades e limites do subprocesso. Provider e ambiente global do PHP permanecem inalterados.

A biblioteca efetivamente carregada e o CLI são **OpenSSL 3.5.7**; o header do PHP informa 3.0.20. PBMAC1 foi aceito tanto no reader isolado quanto na importação integrada desse ambiente. Há teste explícito de AES sem MAC aceito pelo caminho nativo existente e recusado pelo fallback; não é alegada uma política uniforme de MAC. A ponte externa Multiempresa não foi executada nesta rodada; os 19 checks do resolvedor são locais.

## Fontes e bloqueio do PDF

As **30 verificações negativas** cobrem arquivo apenas renomeado, família/estilo incorretos, TTF truncado, manifesto ausente/inválido, hash dos recursos e do programa original, descompressão inválida ou maior que 8 MiB, divergência de metadados e restrições técnicas de incorporação. `Danfe::render()` é interrompido sem fontes originais, sem recorrer ao TCPDF ou fontes do CRM e sem escrever recursos em execução.

Na nota autorizada, XML e consulta oficial permanecem acessíveis; a pendência bloqueia os controles de PDF/envio. Os testes positivos estão preparados para exigir `exact_fonts === true`, Arial/Microsoft Sans Serif incorporadas e pacote completo em `fonts/nt008/`, mas **não foram executados com essas famílias**. Não houve novo PDF renderizado ou inspeção visual de PDF nesta revisão; os resultados com Liberation abaixo são exclusivamente históricos.

## Execução e limites

```bash
PHP_BIN=/caminho/php8.2 sh tools/test.sh
```

Sem as fontes originais, o comando executa as verificações disponíveis e termina com código 2. Após preparar o conjunto conforme [DANFSE_EMBUTIDO.md](DANFSE_EMBUTIDO.md), deve-se repetir os gates de geração, conferir famílias incorporadas e inspecionar o layout.

As verificações DOM usam dependências somente no desenvolvimento. Não houve homologação visual autenticada no tema do portal, banco MySQL/MariaDB real, SMTP real ou certificado ICP-Brasil de produção. O núcleo licenciado e a integração externa de certificados precisam de validação na instalação de destino.

# Histórico — verificação da versão 0.4.4

Data: **10/11/2026**. Validação local com PHP 8.2.32, dados sintéticos, bibliotecas de PDF incluídas no módulo e fixtures de integração. O formulário nativo de fatura foi inspecionado no portal somente para confirmar sua estrutura; esta versão não foi instalada no portal e nenhuma fatura real foi gravada, NFS-e transmitida ou mensagem enviada durante estas verificações.

## Tomadores e preferência por fatura

| Camada | Resultado | Escopo |
|---|---:|---|
| Preferência por fatura | 37/37 | Model e serviço reais; persistência, cliente/empresa, padrões de serviço, primeira preparação e preservação de documentos existentes |
| Hooks e integração | 77/77 | Payload moderno/legado, remoção dos campos antes do SQL, permissões, contexto da empresa, ID real da criação, callbacks repetidas e migração ausente |
| Transporte de interface | 43/43 | Assets e endpoint GET de opções; acesso, métodos, identificadores, ausência de mutação e mensagens sem dados internos |
| Abas e seleção do tomador | 34/34 | Views PHP reais, três abas nativas, CRUD, CSRF, escolha na fatura e no painel, Bootstrap-select real, descarte de respostas atrasadas e isolamento da empresa |
| Regressão do JS compartilhado | 45/45 | Formulários, catálogo, CSRF, modais, tomadores e ações das faturas existentes |
| Sintaxe | 429 arquivos PHP e JavaScript sem erros | Inclui bibliotecas de PDF e novos hooks |

As 34 verificações de interface já incluem as verificações intermediárias das abas; não são contagens adicionais. O CSS do módulo e as views de configurações permaneceram iguais aos da versão 0.4.3. Os testes não modificaram a navegação da aplicação.

O campo fiscal foi conferido contra a estrutura observada no portal: `form#invoice-form`, `select#clientid[name=clientid]` e `.f_client_id`. O salvamento e os hooks foram exercitados em fixtures, sem o núcleo licenciado em execução local ou banco real. A família moderna `before_update_invoice` / `invoice_updated` é a referência; a alteração exclusiva do tomador em versões com os hooks legados não é declarada homologada. Veja [TOMADORES_FATURAS.md](TOMADORES_FATURAS.md).

## DANFSe com recursos internos

- **47/47 verificações** de documento, geração real de PDF e preparo de e-mail, com engine de envio simulado.
- **15/15 verificações** do runtime incluído, isolamento de classes/constantes, fontes preparadas e pacote incompleto.
- PDF sintético e caso com descrição longa renderizados e inspecionados: página A4, QR Code, cabeçalho, campos e texto dentro da área útil.
- Conferência com `pdffonts`: Liberation Sans regular e negrito incorporadas como fontes Unicode em subconjuntos.
- Coexistência exercitada com TCPDF 6.10.1 real do host, nas duas ordens de carregamento. O módulo usa seu próprio gerador e não altera as fontes da instância do host.
- Fontes mantidas somente para leitura durante a geração; conferidos os manifests de integridade e a reprodução dos seis recursos de fonte preparados.

O ensaio confirma disponibilidade técnica do PDF com os recursos distribuídos. **Não certifica identidade tipográfica com a NT 008:** Liberation Sans é distinta de Arial e Microsoft Sans Serif. O diagnóstico e [DANFSE_EMBUTIDO.md](DANFSE_EMBUTIDO.md) explicitam esse limite. Para o DANFSe, o servidor precisa de PHP 8.2 ou superior e das extensões indicadas no README.

## Suíte distribuída e limites

`tools/test.sh` concluiu **445 verificações em 13 suítes**, todas aprovadas: integração (23), SDK (39), serviço (41), dados do CRM (24), resolvedor de certificado (19), hooks (77), contexto da fatura (17), preferência de tomador por fatura (37), catálogo (27), padrões de serviço (36), transporte da interface (43), documento (47) e runtime de PDF (15).

```bash
PHP_BIN=/caminho/php8.2 sh tools/test.sh
```

A geração de PDF é obrigatória nesse runner e utiliza os recursos do pacote; não depende de `TCPDF_FILE` nem de fontes instaladas no Perfex. Os testes de interface usam jsdom, jQuery e Bootstrap somente no ambiente de desenvolvimento. Eles não são dependências de execução do módulo.

**Limites:** o Chromium local não iniciou por restrição de socket e o navegador compartilhado não alcançou a fixture local (`ERR_CONNECTION_REFUSED`). Portanto, a interação DOM foi verificada, mas não houve homologação visual das novas abas dentro do tema ativo. Não houve teste de persistência no banco real, SMTP real ou autenticação fiscal no ambiente nacional. A validação local não substitui a homologação da instalação de destino.

# Histórico — verificação da versão 0.4.2

Data: **10/11/2026**. Revisão limitada à integração de menus, isolamento dos estilos e organização das views. Não houve implantação no portal, transmissão de NFS-e ou envio de e-mail.

## Resultado e escopo

O menu e a guia do cliente são registrados exclusivamente pelas APIs PHP nativas do Perfex. O menu principal **NFSE** possui o ícone `fa-solid fa-file-invoice` e contém somente o submenu **Documentos**, sem ícone. Os assets antigos de navegação permanecem como arquivos inertes para compatibilidade com URLs anteriores; o bundle operacional não reconstrói links nem troca ícones. As configurações continuam na seção **Documentos Fiscais**, fora do menu operacional.

A verificação compara HTML, identidade dos nós e handlers de todos os menus antes e depois do carregamento do módulo, incluindo outros módulos, links `#`, consultas sem caminho e conteúdo inserido por AJAX. Os estilos restantes foram conferidos quanto ao isolamento em conteúdo fiscal. A organização essencial das views usa o grid, painéis e formulários nativos, sem depender do CSS complementar.

| Camada | Resultado | Escopo |
|---|---:|---|
| Hooks e integração | 60/60 | Registro do pai NFSE e filho Documentos, ícones nativos, configurações, permissões, faturas e carregamento restrito dos assets |
| Navegação e isolamento | 110/110 em 18 cenários | DOM, preservação de menus/handlers e compatibilidade do ícone com o CSS Font Awesome público do portal |
| Transporte de assets | 33/33 | Controller real em fixture; mesma cópia executada, hash, sessão, métodos e recusas |
| Estrutura das views | 36/36 | HTML das views PHP reais e componentes nativos disponíveis |
| Fluxos das views | 45/45 | Formulário de configurações único, catálogo, CSRF, tomadores, modais e faturas |
| Consulta no formulário | 13/13 | Preservação dos dados, respostas atrasadas, fila e CSRF |
| Campos condicionais | 132/132 em 12 combinações | Estado antes e depois do JS; modos de tributos e IBS nas views de configurações e documento; valores preservados |
| Sintaxe PHP | 58 arquivos sem erros | Inclui migração 042 sem alteração do banco fiscal |

O teste distribuído de navegação usa jsdom somente no desenvolvimento. O CSS oficial do portal não é redistribuído:

```bash
php tests/hooks_test.php
php tests/ui_transport_test.php
NODE_PATH=/caminho/node_modules NFSE_NATIVE_CSS=/caminho/fontawesome.min.css node tests/navigation_test.js
```

Sem `NFSE_NATIVE_CSS`, a única verificação opcional do glyph é declarada não executada. `NFSE_NAV_REPORT` permite informar o destino de um relatório JSON.

**Limite de validação:** ao abrir o portal no navegador compartilhado, a rota do módulo redirecionou para a tela de autenticação. Não houve inspeção visual autenticada da revisão 0.4.2 no tema ativo. O núcleo licenciado não foi disponibilizado; os contratos de integração com o Perfex foram exercitados por fixtures. Renderização PHP e DOM não equivalem à homologação visual da instalação. As rotinas fiscais e o patch opcional do Multiempresa não foram alterados nesta revisão; os resultados anteriores abaixo são históricos.

# Histórico — verificação da versão 0.4.1

Data: **10/11/2026**. Correção focada na navegação e nas configurações. Não houve implantação no portal, transmissão de NFS-e ou envio de e-mail.

## Regressão reproduzida

O teste anterior não incluía os menus expansíveis `href="#"` presentes nas capturas. A nova matriz reproduziu a alteração indevida de ícones de outros módulos e a inclusão de ícones nos submenus fiscais. Também cobre a consulta sem caminho (`href="?display=compact"`), que preservava o pathname atual e produzia o mesmo falso positivo.

O script corrigido foi conferido nas páginas de notas, configurações nativas e antigas, perfil, guia fiscal do cliente, faturas e painel, com e sem prefixo de empresa. Outros menus e abas são comparados integralmente, incluindo classes, rótulos, links e ações. A conversão de submenu para link é testada com handler de expansão que chama `preventDefault`, garantindo que o clique volte a navegar sem remover os handlers de outros menus.

| Camada | Resultado desta revisão | Escopo |
|---|---:|---|
| Navegação distribuível | 148 verificações em 20 cenários | DOM isolado; menu principal, guia do cliente, preservação dos outros módulos, links genéricos, AJAX, idempotência e handlers |
| Compatibilidade do glyph | 1 verificação adicional | CSS Font Awesome publicado no portal, já obtido; total com CSS: 149/149 |
| Hooks e integração | 50/50 | Bootstrap real com interfaces do núcleo simuladas; menu, grupo de configurações, assets globais, cliente, acesso e faturas |
| Transporte de assets | 33/33 | Controller real em fixture; entrega da própria cópia, hash, sessão, métodos e rejeição de entradas inválidas |
| Fluxos das views | 45/45 | Formulário nativo único, catálogo, CSRF, tomadores, modais e faturas |
| Estrutura das views | 36/36 | HTML renderizado pelas views PHP reais e regras nativas disponíveis |
| Consultas no formulário | 13/13 | Preservação de campos, respostas atrasadas, fila e CSRF |
| Sintaxe PHP do módulo | 57 arquivos, sem erros | Inclui a migração 041 sem alteração fiscal |

O teste de navegação está no pacote em `tests/navigation_test.js`. Requer Node.js e **jsdom somente no ambiente de desenvolvimento**, não no servidor do CRM:

```bash
NODE_PATH=/caminho/das/dependencias/node_modules node tests/navigation_test.js
```

Sem `NFSE_NATIVE_CSS`, o resultado informa expressamente a única verificação opcional não executada. Para conferir também o glyph, forneça `NFSE_NATIVE_CSS=/caminho/fontawesome.min.css`. O módulo não distribui o CSS do portal. `NFSE_NAV_REPORT` permite salvar o resultado JSON em um destino informado.

```bash
php tests/hooks_test.php
php tests/ui_transport_test.php
```

Os ensaios das views e da navegação não substituem a inspeção visual autenticada no tema ativo. O ambiente de testes já havia recusado a inicialização de Chromium e o acesso ao fixture pelo navegador compartilhado; não há capturas de homologação desta instalação. O núcleo licenciado não foi disponibilizado. A assinatura da seção moderna de Configurações continua exercitada como contrato, e não como execução no núcleo real.

As rotinas fiscais, os certificados e as regras de consulta cadastral não foram modificados nesta revisão. Os resultados fiscais abaixo são históricos; não são apresentados como nova homologação.

## Patch opcional do Multiempresa 1.2.4

O patch separado em `tools/multiempresa-update/` foi validado sobre a base 1.2.4 fornecida, sem alterar a referência original ou um servidor real:

- **46 verificações de serviço/controller:** método, acesso, empresa/organização, revisão explícita, módulo ativo, manutenção, precedência da revisão vigente, ordem backup → aprovação → worker e falhas.
- **19 verificações DOM/transporte:** botão, revisão desmarcada, fila, CSRF, bloqueio de clique repetido, isolamento da empresa e recarga do estado.
- **27 verificações do aplicador:** base e hashes, aplicação em cópia isolada, backups, recusa de versão/arquivo diferente e preservação de modo/proprietário/grupo. A falha ao preservar propriedade mantém o arquivo original.

O aplicador somente altera os cinco arquivos descritos pelo patch após verificação e comando explícito `--apply`. A atualização do módulo na empresa continua exigindo uma ação administrativa revisada na interface; não é feita pelo aplicador nem pela ativação de NFS-e. Não houve execução com banco real ou núcleo licenciado.

# Histórico — verificação da versão 0.4.0

Data: **10/11/2026**. Verificação local do pacote com PHP 8.2.32, fixtures de integração e DOM. Não houve instalação na sessão autenticada do portal, emissão fiscal ou envio de e-mail.

| Camada | Verificações aprovadas | Evidência e limite |
|---|---:|---|
| Hooks e integração | 50 | Seção Documentos Fiscais, menu/cliente, URL de assets, permissões e faturas; interfaces do núcleo simuladas |
| Consulta cadastral | 41 | Validação, identidade, fallback, cache por empresa, limites e expiração; transportes simulados |
| Transporte de interface | 33 | Controller real, whitelist, origem da cópia, hash, MIME, cache, HEAD/ETag, métodos, permissões e recusas de lookup |
| Fluxos DOM | 45 | Formulário nativo único, catálogo, CSRF, seleção de tomador, modal e faturas |
| Estrutura nativa | 36 | Views PHP com Bootstrap e CSS públicos do portal; organização sem CSS do módulo e glyphs disponíveis |
| Consulta no formulário | 13 | Preenchimento, preservação de edição, descarte de resposta atrasada, isolamento, fila e CSRF |
| Navegação | 10 | Ícones legados/ausentes, carets, idempotência, AJAX e classes Font Awesome reais |

Foi exercitada também a classe real de consultas do Multiempresa com transporte simulado e SQLite, confirmando o contrato de campos e cache. Não equivale à execução no CRM instalado.

O CSS público `assets/plugins/font-awesome/css/all.min.css?v=3.4.1` do portal informado respondeu 200 e identifica **Font Awesome Free 6.2.0**. Contém `fa-file-invoice` e não contém `fa-file-text-o`. A fonte referenciada `fa-solid-900.woff2` respondeu 200. Foram usados também os CSS públicos Bootstrap/reset da própria instalação para conferir a estrutura. Não foi obtido ou redistribuído o núcleo licenciado do Perfex.

Uma consulta HTTPS ao exemplo público do ViaCEP respondeu 200 com o contrato esperado pelo adaptador. A tentativa pelo runtime PHP privado deste ambiente não estabeleceu conexão antes do timeout; por isso, o transporte PHP completo **não é declarado homologado ao vivo**. Os testes automatizados de normalização e fallback usam respostas controladas.

**Limitação de validação visual:** Chromium/Playwright foi tentado e recusou a criação do socket de inicialização (`Operation not permitted`). O navegador compartilhado também não alcançou o fixture HTTP (`ERR_CONNECTION_REFUSED`). Não foram produzidas capturas reais das novas views no CRM. A conferência de HTML/DOM e dos estilos publicados não substitui a revisão visual autenticada no tema ativo.

Os resultados históricos abaixo pertencem às versões indicadas. As suítes fiscais completas não foram reexecutadas nesta alteração de interface/consultas, e não são apresentadas como nova homologação fiscal.

## Executar as verificações específicas

```bash
php tests/hooks_test.php
php tests/registration_lookup_test.php
php tests/ui_transport_test.php
```

Os testes de transporte não iniciam o núcleo Perfex: verificam o controller por fixtures e não substituem o middleware nativo de sessão/CSRF nem seu roteador. A migração 040 foi revisada como alteração sem efeito sobre o banco fiscal.

# Histórico — verificação do hotfix 0.3.1

A correção de navegação foi verificada separadamente: **50 verificações de hooks** (incluindo carregamento no dashboard e na guia Perfil) e **10 cenários de navegação em jsdom sem Font Awesome**. Os testes conferem ícones ausentes e legados, preservação de textos/setas, idempotência, conteúdo AJAX e isolamento de rotas. Foram verificadas a sintaxe dos arquivos alterados e a existência do SVG local. Não houve alteração nem nova homologação dos fluxos fiscais.

A conferência DOM não substitui a inspeção no tema ativo da instalação. Ao atualizar, confirme a versão 0.3.1 em Módulos. Se arquivos PHP antigos persistirem por política de OPcache do servidor, o administrador deve recarregar o processo PHP pelo painel de hospedagem.

# Verificação da versão 0.3.0

Data: **10/11/2026**. Esta verificação foi feita durante a construção do pacote, com dados sintéticos. Não houve emissão fiscal, conexão autenticada à SEFIN nem envio real de e-mail.

## Resultado

| Camada | Verificações aprovadas | Condições |
|---|---:|---|
| Integração e autorização | 23 | Contextos Perfex e banco simulados |
| SDK fiscal | 39 | Certificado temporário de teste, XML, assinatura, XSD e transporte simulado |
| Serviço de emissão | 41 | Persistência e respostas nacionais em memória |
| Documento, PDF e e-mail | 39 | Parser real, TCPDF real e engine de e-mail simulado |
| Reaproveitamento cadastral e atualização | 24 | Dados nativos e modelo de configuração, com entradas sintéticas |
| Resolução de A1 | 31 | 19 verificações do resolvedor e 12 com classes reais do Multiempresa em SQLite temporário |
| Hooks e integração nativa | 46 | Registro de seções, ação por linha na grade, prévia da fatura e interfaces Perfex simuladas |
| Vínculo da fatura | 17 | Modelo real com consultas simuladas |
| Catálogo nacional | 27 | Integridade, códigos, descrições, extração e disponibilidade por ambiente |
| Preferências de serviço | 36 | Persistência e preparo reais do modelo, com banco em memória e serviço real |
| **Total PHP** | **323** | **Dez suítes offline incluídas no pacote** |
| Interface | 45 | Harness PHP, jsdom, Bootstrap 3.4.1, Bootstrap-select 1.13.18 e respostas HTTP simuladas |

Também passaram a verificação de sintaxe **49 arquivos PHP** e o JavaScript do módulo. O runtime usado foi PHP 8.2.32, 64 bits. A geração de PDF foi exercitada com TCPDF 6.10.1. Esses resultados não substituem a homologação no núcleo e na versão de dependências da instalação de destino.

## O que as verificações exercitam

### Autorização e isolamento

- Permissão por ação, sessão de colaborador e acesso ao cliente e à fatura.
- Escopo de banco/prefixo da empresa ativa e rejeição de contexto inconsistente.
- Impossibilidade de usar um tomador vinculado a outro cliente.
- Recuperação administrativa do arquivo fiscal quando o registro financeiro de origem não existe.

Os testes usam objetos de banco simulados. Não foi executado um servidor MySQL/MariaDB real nem uma disputa de locks entre processos independentes. A reserva transacional e os índices precisam ser exercitados no banco da homologação.

### A1 da empresa e integração nativa

- Prioridade local, ausência de certificado e bloqueio de alternativa quando o serviço nega acesso ou retorna dados inválidos.
- Conferência de CPF/CNPJ, contexto, usuário, funcionário, vínculo e organização.
- Duas empresas com o mesmo CNPJ permanecem isoladas.
- Revogação de vínculo ou autenticação impede obter o certificado.
- Exercício das classes reais de autenticação, catálogo, certificados e auditoria do Multiempresa fornecido, usando SQLite e certificados temporários de teste.
- Registro moderno/legado da seção de Configurações e ausência de formulário aninhado.
- Painel de fatura com ambos os ambientes e permissões por ação.
- Reaproveitamento cadastral sem sobrescrever valores fiscais explícitos, inventar município ou misturar dados de endereços diferentes.
- Mudança de cliente da fatura bloqueia nova preparação e não expõe os dados do cliente anterior.

O ponto de extensão para A1 no núcleo personalizado foi testado como contrato, com provedores simulados. O cadastro específico desse núcleo não foi disponibilizado e seu adaptador não foi implementado por adivinhação. A seção moderna `app->add_settings_section()` e os hooks foram exercitados com interfaces simuladas, não no núcleo licenciado.

### Catálogo e padrões do tomador

- Catálogo íntegro com 338 códigos de produção e sete adicionais identificados como exclusivos de testes; total de 345 para pesquisa cadastral.
- Códigos como strings de seis dígitos, preservando zeros iniciais, descrições longas e todos os desdobramentos; itens e subitens de agrupamento não entram como códigos emitíveis.
- Recusa de códigos inexistentes, CNAE e NBS no campo de tributação nacional.
- Gravação e recuperação de serviço e descrição no perfil e no tomador específico; operações inválidas preservam o cadastro anterior.
- Padrões aplicados no fluxo real de preparação pela fatura, com prioridade tomador → cliente → empresa e descrição da fatura quando todos estão vazios.
- Novo clique reutiliza o rascunho e não sobrescreve seu conteúdo com alterações posteriores do cadastro.
- Troca explícita do tomador aplica padrões no servidor, preservando alterações visíveis feitas pelo operador sem modificar o cadastro original.
- Isolamento por cliente e prefixo; bloqueio de gravação após cancelamento ou transferência da fatura a outro cliente.
- Validação antes da assinatura impede código exclusivo de testes em produção.

A procedência e os limites de comparação dos arquivos governamentais estão em CATALOGO_SERVICOS.md. A inclusão no catálogo não certifica todas as combinações fiscais de cada serviço.

### Certificado, XML e contrato de API

- Importação e conferência de certificado de teste, chave privada e identidade esperada.
- Assinatura XML e detecção de alteração posterior.
- Geração e validação da DPS nos dois conjuntos de XSD incluídos.
- Compactação GZip/Base64, identificação da DPS, ambientes e formato das respostas.
- Rejeição fiscal, erro de transporte, conteúdo inválido e limites de resposta.

O certificado é gerado temporariamente pelo teste. Ele não é uma identidade ICP-Brasil e não comprova aceitação pela SEFIN. Os XSDs foram obtidos de espelho público; a procedência e os hashes estão em `schemas/provenance.json`. Não houve comparação binária com o ZIP oficial, que retornou HTTP 403 neste ambiente.

### Emissão, correlação e recuperação

- Reserva estável da DPS antes da chamada remota.
- Repetição da ação sem nova numeração quando já existe uma emissão em andamento ou autorizada.
- Persistência do XML enviado e recuperação por consulta.
- Timeout e HTTP 500 mantêm resultado incerto e bloqueiam edição.
- Rejeição seguida de consulta sem confirmação também permanece incerta.
- Reenvio explícito preserva exatamente o XML assinado, a série e o número originais.
- NFS-e com a mesma DPS, mas outro tomador ou valor, não recebe vínculo com a fatura.
- Enriquecimento de cadastro é permitido; duplicidades ou namespaces ambíguos não são aceitos.
- Revisão de valores quando a fatura muda e bloqueio de alterações após autorização.

A correlação compara os valores enviados com a DPS devolvida no XML. Prefixos e indentação não interferem, mas uma transformação textual feita pelo ambiente nacional pode levar ao estado incerto. A primeira resposta real de homologação deve confirmar se existe normalização que exija tratamento adicional.

### DANFSe e envio

- Parser seguro, namespace correto, grupos únicos, chave, DPS, datas e ambiente.
- Rejeição de DTD/XXE, documentos excessivos e campos críticos duplicados.
- PDF real em uma página A4, com identificação de homologação, logomarca, QR Code e campos fiscais.
- Uso exclusivo dos dados do XML autorizado e de descrições públicas de códigos.
- E-mail com exatamente os bytes de XML e PDF esperados, sem reaproveitar destinatários ou anexos anteriores.
- Bloqueio de injeção de cabeçalho, pré-validação de configuração e ausência de repetição automática após falha SMTP.

O DANFSe sintético foi renderizado em imagem e inspecionado visualmente: não apresentou sobreposição nem recorte no exemplo testado. A fonte usada foi FreeSans. A conformidade tipográfica integral da NT 008 depende de Arial e Microsoft Sans Serif licenciadas e instaladas no servidor. Nenhum e-mail foi enviado a um servidor SMTP real.

### Interface

As 45 verificações conferiram renderização das telas, campos, permissões, rascunho incompleto, seleção de tomador, revisão de valores, bloqueio de duplo clique, renovação do CSRF, serialização de requisições, escape de HTML, modal, ações por estado, arquivo órfão e confirmação para repetir e-mail incerto. Também verificaram formulário nativo com multipart, validador jQuery pré-existente, preservação do handler de outras seções, painéis AJAX, atualização da fatura, modal complementar com ESC e formatos nativos de valores/datas sem modificar os dados de entrada. Os novos cenários usam a implementação original do Bootstrap-select para pesquisar código, máscara e descrição sem acentos; conferem o envio das preferências, o modal do tomador, a preservação de código histórico e a ação da grade após redesenho da DataTable.

Esse harness foi usado somente no ambiente de desenvolvimento e não integra o pacote instalável. O teste ocorreu em DOM simulado; não foi possível iniciar uma sessão visual de navegador conectada à prévia local. Não se declara, portanto, validação visual das telas no Perfex real ou compatibilidade com temas personalizados.

## Reproduzir as suítes incluídas

Na pasta do módulo, com PHP e suas extensões disponíveis:

```bash
PHP_BIN=php sh tools/test.sh
```

A partir da versão 0.4.4, o gerador e as fontes estão no pacote e a geração real do PDF é obrigatória na suíte. Use PHP 8.2 ou superior. Para também guardar os arquivos sintéticos gerados:

```bash
PHP_BIN=php \
PDF_TEST_OUTPUT=/tmp/nfse-nacional-teste \
sh tools/test.sh
```

Para a parte opcional que executa as classes reais do Multiempresa, disponibilize PDO SQLite no PHP de testes e a pasta do módulo de referência:

```bash
NFSE_MULTIEMPRESA_ROOT=/caminho/modules/multiempresa \
php tests/certificate_resolver_test.php
```

Sem essas dependências, a suíte executa 19 verificações do resolvedor e informa explicitamente que a ponte Multiempresa foi pulada. Na validação desta entrega, foram executadas todas as 31. O `tools/test.sh` não transforma uma etapa opcional pulada em afirmação de cobertura completa.

Os dez arquivos de teste só executam em linha de comando. Não recebem certificado, senha, destinatário ou configuração de uma instalação real e não realizam transmissão nacional.

## Validação pendente no servidor

Siga `HOMOLOGACAO.md` para concluir ativação, comportamento do banco, concorrência real, carregamento de dependências, permissões nativas, certificado ICP-Brasil, aceite fiscal municipal, retorno da SEFIN e entrega SMTP. Produção deve permanecer desabilitada até essa verificação.
