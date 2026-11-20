# 0.8.9 — Leitura de eventos históricos e retomada da distribuição ADN

- Corrige o bloqueio real de um lote com HTTP 200 e 50 documentos: eventos históricos usam `infEvento/nDFe`, enquanto o leitor exigia exclusivamente `nDFSe`.
- Aceita os dois nomes oficiais do número do registro, exigindo exatamente um campo direto no namespace nacional. Duplicidades, valores inválidos, chave/ambiente divergentes e pedidos sem evento registrado continuam recusados.
- Preserva o XML original assinado, a confirmação fiscal, o cursor, os documentos e os intervalos existentes. Não salta eventos nem reinicia o histórico.
- Diagnóstico de evento informa somente mensagens e nomes de campos internos controlados pelo aplicativo; não inclui XML ou identificadores fiscais.
- Migração 089 sem SQL. Base 0.8.8 e migrações anteriores preservadas.
- Correção instalada e consulta autenticada validada no portal, com avanço além de dezembro de 2024. Evidências, testes e limites em [ATUALIZACAO_089.md](docs/ATUALIZACAO_089.md).

# 0.8.8 — Retomada de fila ativa sem bloqueio 409 por seleção

- Corrige a falha confirmada pelo diagnóstico da 0.8.7: uma diferença entre o modo/período solicitado e os metadados da fila ativa interrompia o início antes de consultar o ADN.
- Histórico completo e período continuam usando o mesmo fluxo por NSU. A seleção agora é atualizada na fila existente sob lock, preservando NSU, documentos, contadores, início da execução e pausas do serviço.
- Datas residuais em uma execução de histórico completo são normalizadas sem excluir a execução nem reiniciar o histórico.
- Períodos inválidos continuam recusados, e uma execução efetivamente concorrente permanece protegida pelo lock.
- Migração 088 sem SQL ou alteração de dados. Testes e instruções em [ATUALIZACAO_088.md](docs/ATUALIZACAO_088.md).

# 0.8.7 — Recuperação da busca nacional e da continuidade dos lotes

- Iniciar/Retomar volta a executar uma consulta imediatamente, em vez de apenas devolver a mesma pendência criada desde a 0.8.3.
- Execuções ativas são reagendadas sem reiniciar o NSU nem perder contadores. A retomada respeita pausas já confirmadas pelo serviço.
- Corrige a autorização do worker para usuários com as permissões atuais, sem exigir adicionalmente a capacidade antiga de consulta nacional.
- Mantém o cron e acrescenta continuação protegida por POST na tela aberta; GET de progresso permanece somente leitura. A listagem atualiza ao concluir.
- Diferencia Retry-After/429 da espera de uma hora após ausência confirmada, preservando o cursor e os limites de consumo.
- Revalida estado e próxima tentativa sob lock antes de processar, evitando trabalho duplicado por seleção simultânea.
- Mostra última execução, próxima tentativa, fila sem atividade recente e falha por etapa. O cliente usado pelo cron registra os diagnósticos de API sanitizados.
- Migração 087 aditiva/retomável, somente para o horário da última execução. Nenhum cursor, XML, certificado, credencial ou migração anterior é reescrito.
- Comparação das versões, testes e limites em [ATUALIZACAO_087.md](docs/ATUALIZACAO_087.md).

# 0.8.6 — ISSQN por competência, separado ou compartilhado

- Checkbox independente para alíquota mensal do ISSQN e opção explícita de utilizar o percentual mensal do Simples.
- Simples e ISSQN podem ser utilizados individualmente, em conjunto com valores separados ou compartilhados, mantendo o método IBPT e os demais controles independentes.
- Duas guias na mesma tela de competências, com CRUD e permissões existentes. Cadastros anteriores do Simples não são alterados.
- Preparação, validação e transmissão manual/automática resolvem o mês fiscal de cada percentual necessário e bloqueiam quando faltar cadastro.
- Percentuais e origem registrados em snapshots; documentos emitidos preservados. Nenhuma conversão ou redução aplicada.
- Migração 086 aditiva e retomável para a tabela própria do ISSQN, na conexão/prefixo nativos. Limite fiscal existente do ISSQN mantido, inclusive no compartilhamento.
- Instruções e validações: [ATUALIZACAO_086.md](docs/ATUALIZACAO_086.md).

# 0.8.5 — Alíquotas editáveis e permissões por recurso e relatório

- Corrige a grade mensal que permanecia em carregamento: linhas, pesquisa e paginação são renderizadas no servidor, sem herdar configuração AJAX global do DataTables.
- Edição identifica a competência original e atualiza o registro existente; inclusão duplicada continua recusada.
- A alíquota variável altera apenas o percentual do Simples. IBPT, método de estimativa, ISS/retenção e IBS/CBS permanecem independentes e editáveis.
- Configurações mostram a alíquota cadastrada para o mês consultado, com aviso quando ausente e preservação da alíquota fixa anterior.
- Permissões nativas separadas para alíquotas, automações e cada relatório, com validação também nas rotas e exportações.
- Migração 085 sem SQL ou atribuição automática de permissões. Preserva todas as migrações e arquivos anteriores.
- Validações e instruções em [ATUALIZACAO_085.md](docs/ATUALIZACAO_085.md).

# 0.8.4 — Prefixo vazio sem dependência do modo Multiempresa

- Remove apenas a condição `mode=database` para aceitar prefixo vazio em `nfse_nacional_scope()`.
- Mantém conexão nativa, prefixos literais, validação dos prefixos preenchidos e cálculo criptográfico anterior.
- Não cria segredos, configurações ou responsabilidades de provisionamento no NFS-e.
- Preserva os recursos da 0.8.3 e todas as migrações. Migração 084 sem SQL ou alteração de dados.
- 126 verificações SQL em seis execuções, incluindo operação sem flags/classes Multiempresa e callback real de ativação. Detalhes em [ATUALIZACAO_084.md](docs/ATUALIZACAO_084.md).

# 0.8.3 — Autenticação compartilhada, automações independentes e sincronização durável

- Corrige prefixo vazio também no Perfex independente e nos repositórios de documentos e eventos, preservando a derivação criptográfica.
- Simplifica a autenticação para um ambiente/método e um A1 local compartilhado. Preserva credenciais diferentes anteriores e exige escolha explícita nos conflitos.
- Divide a automação em criação e pagamento integral, com seleções, filas e acompanhamento por tipo. Serializa emissão manual e automática por fatura e consulta DPS inconclusivas sem reenvio automático.
- Diferencia inclusão e edição de alíquota mensal, recusa duplicação e preserva a configuração fixa.
- Mantém os destinatários existentes e acrescenta paginação e busca em toda a grid.
- Cria execução persistente de sincronização completa/por período, continuidade dos lotes, tentativas e contadores associados ao cursor confirmado.
- Acrescenta migração 083 e tabela no manifesto opcional de integração. Nenhuma migração anterior ou arquivo foi removido.
- Resultados e limitações: [RELATORIO_083.md](docs/RELATORIO_083.md).

# 0.8.2 — Provisionamento em banco exclusivo sem prefixo

- `nfse_nacional_scope()` aceita prefixo vazio exclusivamente no contexto confiável do Multiempresa em modo `database`. Prefixo vazio em banco compartilhado, ausência de contexto e divergência de prefixo permanecem recusados.
- Preservado o cálculo de escopo das instalações já existentes, evitando alteração de suas chaves de criptografia.
- Manifesto `multiempresa.json` declara as 15 tabelas do instalador para conceder permissões por tabela ao usar banco compartilhado, sem acesso ao banco completo ou a empresas vizinhas.
- Migração 082 sem alteração de estrutura ou dados.
- Reproduzida a falha real da versão 0.8.1 e validada a retomada das etapas pendentes com preservação dos dados. Para a revisão visual e o teste de conexão, usar Multiempresa 1.2.6.

# Histórico de versões

## 0.8.1 — Alíquotas mensais, emissão automática e destinatários

- Alíquota cheia do Simples por competência, cadastro no menu NFSE e bloqueio de validação/transmissão quando falta o mês. Preserva snapshots de DPS e documentos emitidos.
- Fila de emissão por pagamento integral ou criação, modos desabilitado/todos/selecionados, seleção individual na nova fatura, prazo padrão de 30 minutos e conferência de duplicidade, cancelamento e exclusão.
- Guia Destinatários de e-mail com grid pesquisável, inclusão/edição/remoção e salvamento individual; removidos os limites de dez cadastros e destinatários no envio.
- Cadastro único criptografado de usuário e senha para os dois ambientes. A1 preservado; autenticação por senha nos endpoints nacionais permanece sem contrato oficial confirmado.
- Migração 081 e pacote completo. Regras, configuração do cron, limitações e validação em [ATUALIZACAO_081.md](docs/ATUALIZACAO_081.md).

## 0.8.0 — NBS pesquisável na tabela IBPT local

- Os campos NBS do rascunho, serviço padrão e perfis de emissão (cliente e tomador) utilizam o catálogo de NBS da tabela IBPT já instalada, com pesquisa por código ou descrição, seguindo o seletor de serviços nacionais.
- A busca é local, isolada por empresa e usa automaticamente a UF cadastrada do emitente; não existe seletor adicional de UF nem download durante a busca.
- Tabelas vigentes ou vencidas permitem consulta de NBS. Sem tabela instalada, mantém-se a digitação manual de 9 dígitos.
- Valores previamente salvos que não constem na tabela atual são preservados para revisão; a alteração de catálogo não reescreve rascunhos ou documentos autorizados.
- Nenhuma modificação de esquema, credenciais ou lógica de emissão.

## 0.7.9 — 2026-11-12

- Corrigida a distribuição do ADN (produção e homologação): o campo auxiliar `DataHoraGeracao` ausente, com formato divergente ou data inválida não interrompe mais um lote fiscalmente válido. Quando possível, usa a data do XML já validado, sem alterar NSU ou chave e sem inventar datas.
- Reconhece timestamps ISO-8601 com até 9 casas decimais em metadados do ADN e nos XMLs recebidos; mantém as exigências de ambiente fiscal, chave, assinatura/estrutura e sequência.
- Observabilidade: inclui `generation_date_fallbacks` (quantidade, sem expor dados fiscais) nos logs sanitizados de distribuição, preservando a indicação de sucesso da consulta quando o lote é aceito.
- **Baixar diagnóstico detalhado (JSON)** em **NFSE → Logs**, com filtros; download da operação completa pelo evento selecionado, inclusive na tela de detalhes e na grid. Apresenta linha do tempo, ambiente, HTTP, etapas, tempo, tamanho/hash, causa e classe do erro sanitizado, totais, status de processamento e indicadores de recuperação das datas.
- Diagnóstico HTTPS ADN aprimorado: tempos DNS, conexão, TLS e primeiro byte, número cURL e categoria da falha, sem gravar mensagens brutas, arquivos de certificados, corpo HTTP nem dados sigilosos. Exporta apenas o escopo da empresa autenticada, dentro da retenção existente.
- Migração `079` sem alterações no banco nem nos documentos. Não houve transmissão real, somente testes locais com respostas simuladas.

## 0.7.8 — 2026-11-12

- Autenticação por **Produção (1)** e **Produção restrita (2)** configurável independentemente na guia **Autenticação API**. A1 válido nos dois ambientes e sem dependência de uma única configuração global.
- Resolução do A1 por ambiente com três fontes: cadastro da empresa/Multiempresa (e local quando autorizado), somente cadastro da empresa, ou A1 exclusivo do ambiente. Não compartilha o A1 específico do ambiente oposto.
- Cadastro de usuário/senha **protegido, isolado por empresa e ambiente**, preparado para compatibilidade futura. Não envia credenciais do Portal Web à API SEFIN/ADN, pois os endpoints atualmente documentados não descrevem essa autenticação. A seleção desse método produz aviso operacional e impede a requisição não suportada; nenhuma emissão por senha é anunciada.
- Armazenamento de credenciais fora de `settings_json`, criptografado via AES-256-GCM com chave e escopo da empresa; campos não retornam senhas ou arquivos na interface/logs. Migração `078` incremental, sem sobrescrever A1, documentos ou preferências anteriores.
- Consultas, transmissão, conciliação de DPS, eventos e sincronização agora resolvem certificado pela identidade fiscal e pelo **ambiente persistido em cada documento**, evitando troca indevida de ambiente durante operações posteriores.
- Testes offline específicos de isolamento/criptografia e validação de continuidade da migração. **Sem afirmar teste real de transmissão/credenciais não disponíveis**.

## 0.7.7 — 2026-11-12

- Inclui a identificação do desenvolvedor no cabeçalho nativo de módulos do Perfex CRM: **WWSoft -> Wallace Kleiton**, com URI do autor informado no módulo Asaas fornecido como referência.
- Mantém o nome, a descrição, as permissões, as configurações, os cálculos e as rotinas de NFS-e da versão 0.7.6. Não há dependência do SDK Asaas para exibir o crédito.
- Migração 077 sem alterações de banco ou dados fiscais. O upgrade é estritamente de metadados e versão.

## 0.7.6 — 2026-11-12

- Parâmetro por empresa: **Permitir instalar tabela IBPT de versão anterior (API ou CSV)**, desabilitado por padrão. Exige salvamento prévio nas configurações; protege contra substituição regressiva acidental.
- Quando autorizado, **Atualizar tabela agora** aceita uma versão anterior devolvida pela API WWSoftwares e **Importar tabela** aceita uma versão anterior do CSV, com UF/NBS/chave/período válidos e preservação da cópia em caso de falha.
- A escolha explícita de tabela anterior suspende a substituição automática posterior, para que a versão escolhida não seja trocada na emissão seguinte. Uma nova atualização manual bem-sucedida pode retomar a renovação normal.
- Quando a chave não consta no CSV, pode ser **cadastrada junto com a tabela no envio**, fica gravada com versão, UF, hash e vigência, e o painel informa a origem da chave. Chave ausente sem preenchimento continua recusada; não se inventa nem copia chave de outra versão.
- Preserva a regra de IBPT vencido não bloqueante e a tarja discreta opcional; não regrava documentos e estimativas anteriores. Migração 076 sem mudança de esquema.

## 0.7.5 — 2026-11-12

- **IBPT vencida não bloqueia o cálculo, a validação da DPS, a geração do DANFSe nem a tentativa de transmissão** quando existe snapshot local estruturalmente válido para a UF/NBS do emitente. São preservadas chave, versão, fonte, vigência e alíquotas realmente utilizadas.
- Renovação automática da tabela vencida ao usar o módulo, com até uma tentativa por hora após resposta desatualizada ou falha; cache anterior continua disponível, inclusive durante atualização concorrente. Não há novas consultas por cada nota.
- **NFSE → Documentos**: tarja compacta “IBPT vencida”, meramente informativa, sem modal, confirmação, botão de fechar ou alteração dos controles de emissão.
- Em **Tabela IBPT**, novo parâmetro **Exibir aviso discreto de IBPT vencida na tela Documentos**, **ativado por padrão** e persistido por empresa; ocultá-lo altera apenas a interface.
- Importação manual pode aceitar tabela vencida consistente; continua rejeitando UF/versão/chave incoerentes, dados futuros, arquivos malformados e retrocesso sobre tabela mais atual.
- Migração `075` sem alteração de esquema, documentos, certificados ou dados já assinados.

## 0.7.4 — 2026-11-11

- Reconhecimento automático de diferentes leiautes CSV no envio manual: nomes equivalentes de colunas, ordem variável e tipos `ncm`, `nbs` e `lc116`, preservando o leiaute numérico original.
- Coluna `UF` conferida com o emitente permite arquivos com nomes genéricos; arquivos sem essa coluna continuam exigindo UF no nome original. Somente registros NBS participam do cálculo da NFS-e.
- Campo opcional **Chave IBPT (quando ausente no arquivo)** para complementar exportações sem essa informação. Não inventa nem reaproveita chaves de outra versão e recusa divergência com a chave presente no CSV.
- Fonte declarada **IBPT** quando a exportação não contém fonte; versão, período e hash dos bytes originais preservados. Arquivos recusados não substituem a tabela anterior.
- Migração `074` sem mudanças de esquema ou documentos fiscais.

## 0.7.3 — 2026-11-11

- Opção **Enviar tabela manualmente** na guia **Tabela IBPT**, com seleção de CSV e botão **Importar tabela**, sem salvar ou recarregar as configurações.
- Importação local sem consulta externa, isolada por empresa/UF; limite de 8 MB, arquivo original com UF no nome, vigência atual e consistência de NBS, alíquotas, chave e versão.
- Arquivos inválidos, vencidos, futuros, de outra UF ou anteriores à tabela local vigente são recusados antes de substituir a cópia armazenada.
- Identificação **Arquivo enviado** ou **Consulta à fonte** no painel; tabela importada reutilizada nas emissões até o vencimento. Uma consulta externa regressiva preserva o arquivo vigente enviado.
- Rota protegida por permissões, CSRF e contexto salvo do emitente; registro da operação em **NFSE → Logs**. Migração `073` sem mudança de esquema ou de documentos existentes.

## 0.7.2 — 2026-11-11

- Guia nativa **Tabela IBPT** nas configurações, com UF do emitente, situação, versão, chave, fonte, período de vigência, datas de obtenção/tentativa e número de serviços.
- Tabela vigente reutilizada localmente até o fim da vigência; abrir configurações ou emitir outras notas não baixa novamente o arquivo vigente.
- Renovação automática ao preparar/salvar rascunhos com IBPT quando a tabela estiver ausente ou vencida; intervalo de uma hora após falha ou fonte sem vigência para evitar consultas repetidas.
- **Atualizar tabela agora** permite renovação manual, sem NBS obrigatório e sem salvar outros campos da configuração. Proteção de permissão, CSRF, empresa/UF e intervalo mínimo de um minuto.
- Consulta local não altera o arquivo armazenado. Uma fonte inválida ou regressiva não substitui a tabela local ainda vigente; XMLs e estimativas de documentos já transmitidos permanecem preservados.

## 0.7.1 — 2026-11-11

- Cálculo IBPT automático por UF do emitente e NBS exato, com alíquotas federal nacional, estadual e municipal. Leitura do CSV em UTF-8/Windows-1252, cache por empresa/UF, validade e chave/versão conservadas em cada rascunho.
- Opção nas configurações nativas e nos perfis de clientes/tomadores, com herança preservada. Recalcula na preparação/gravação; falhas externas permitem salvar o rascunho, mas impedem transmitir com estimativa IBPT não confirmada ou vencida.
- DPS usa `vTotTrib` e informação complementar com fonte/chave, sem duplicar `pTotTribSN` nem modificar a descrição do serviço. Não aceita fonte, chave ou alíquotas IBPT submetidas pelo navegador.
- ISSQN destacado por base, alíquota, valor e retenção; prévia com cálculo local identificado e DANFSe autorizado fiel ao XML. Percentual municipal IBPT não substitui a alíquota do ISSQN.
- Omissão de `pAliq` nos casos conhecidos em que o leiaute de negócio veda a informação: MEI, não incidência, regime especial e ISS no Simples sem retenção. Validação local do limite de 5% do ISSQN.
- Consulta IBPT incluída nos logs existentes, sem armazenar o CSV bruto ou dados cadastrais. Migração `071` sem alteração de esquema; nenhuma mudança nos menus, ícones ou CSS globais.


## 0.7.0 — 2026-11-10

- Recuperação de resposta perdida pela DPS original ou pela chave conhecida, sem novo POST fiscal, com correspondência do XML e histórico da confirmação.
- Acervo nacional de emitidas e recebidas por distribuição ADN/NSU, isolado por empresa e ambiente, com persistência transacional, XML cifrado, prévia e downloads.
- Pacote mensal por competência, direção e formato, deduplicação de chave, ZIP interno, destinatários cadastrados e envio nativo com proteção contra duplicidade.
- Eventos assinados de cancelamento, análise fiscal e manifestações do tomador; recuperação de pedidos, preservação da prova registrada e download do XML confirmado.
- Rascunhos de substituição para notas locais, revisões vinculadas à fatura, regras de identidade/competência e consulta do cancelamento da origem após autorização da substituta.
- Novas permissões e logs por operação; migrações 060/070 restritas às tabelas fiscais do módulo. Fontes originais e interface nativa preservadas.
- Correção do reenvio de substituição para revalidar permissões e conflito de cancelamento; eventos retomados conferem a identidade fiscal completa do pedido assinado.
- Sequência intermediária de migrações completa para o atualizador nativo do Perfex; versões sem mudança de esquema não alteram os registros existentes.

## 0.5.0 — 2026-11-10

- Endereço completo do estabelecimento na guia Emitente das configurações nativas: CEP, logradouro, número, complemento, bairro, município, UF e IBGE, com consulta por CNPJ e CEP.
- Persistência nos parâmetros da empresa ativa; validação de CEP/UF e consistência do IBGE. Endereço nativo é reaproveitado como conjunto, sem misturar campos com um endereço fiscal já revisado.
- Novos rascunhos e prévias recebem o endereço salvo. DPS reservadas e notas autorizadas conservam seu snapshot; o DANFSe autorizado continua fiel ao XML nacional. A DPS do próprio prestador não envia nome/endereço, conforme o cadastro oficial RFB/CNC.
- Nome explícito de produção restrita/homologação na seleção do ambiente; conferência documental das bases SEFIN e ADN e testes de roteamento em ambos os ambientes.
- Migração `050` sem alteração de tabelas. Menus, estilos globais, certificados e sequências permanecem preservados.

## 0.4.9 — 2026-11-10

- Pré-visualização e download do DANFSe para rascunhos incompletos, documentos validados, rejeitados e com confirmação pendente, sem certificado, reserva de DPS, escrita fiscal ou chamada à API.
- Layout e fontes originais embutidas reutilizados. Prévias locais não apresentam chave, número de nota, protocolo ou QR de autenticidade inventados. Carimbos distinguem Rascunho, Não transmitida, Não autorizada após correção, Rejeitada e Aguardando confirmação.
- Notas autorizadas usam exclusivamente o XML recebido; notas canceladas preservam esse XML e exibem carimbo apoiado em evento fiscal registrado e correlacionado. Homologação mantém o aviso de ausência de validade jurídica.
- Painel de situação fiscal com transmissão, processamento, chave, identificação da DPS, última consulta e identificação do evento de cancelamento. Protocolo só é exibido quando fornecido, sem confundir DPS, chave ou registro do evento com um protocolo de NF-e.
- Ação **Atualizar situação** consulta eventos por GET autenticado. Pedido de cancelamento e indeferimento não cancelam a nota. Respostas ambíguas, falhas ou HTTP 404 genérico preservam a situação anterior e não repetem emissões ou e-mails.
- Prévias disponíveis no documento, listagem, cliente e painel da fatura, com componentes nativos. Notas emitidas incluem canceladas; edição, transmissão e envio de canceladas são bloqueados.
- Migração `049` adiciona somente `fiscal_meta_json` e `cancellation_event_enc` à tabela de documentos do módulo na empresa ativa. Evento confirmado é cifrado e revalidado ao gerar o carimbo. Prévias e consultas ficam nos logs, sem XML bruto.

## 0.4.8 — 2026-11-10

- Adicionado **NFSE → Logs**, com filtros, paginação, detalhes e exportação JSON Lines; permissão própria e isolamento pelo contexto autenticado da empresa.
- Registro de início, conclusão e falha das operações do módulo, com referência compartilhada pelos eventos HTTP. Erros apresentam referência e atalho para o diagnóstico quando autorizado.
- Consulta municipal identifica o serviço ADN e lê a mensagem do retorno. HTTP 404 é inconclusivo e não equivale automaticamente à ausência de convênio.
- Observador opcional da API registra metadados após validar o resultado: ambiente, método, endpoint parametrizado, HTTP, duração, tamanhos, hashes, tipo de resposta e erros tratados; falhas do observador não alteram o resultado fiscal.
- Reenvio conservador: 404 de rota, gateway ou resposta genérica na consulta da DPS não autoriza outro POST nem cria nova numeração.
- Arquivos protegidos, sanitização na escrita e leitura, rotação, retenção limitada, concorrência e falha de armazenamento sem interromper a emissão. Senhas, certificados, chaves, headers de autenticação e XML bruto não são gravados no log.
- Migração `048` sem alteração de esquema. Fontes originais continuam incorporadas e verificadas no empacotamento; não altera estilos globais, ícones dos outros menus ou localização das configurações.

## 0.4.7 — 2026-11-10

- Arial regular, Arial Bold e Microsoft Sans Serif originais, fornecidas pelo usuário, foram preparadas e incorporadas ao pacote em `vendor/pdf-runtime/fonts/nt008/`.
- O DANFSe usa exclusivamente os recursos internos já prontos: nenhuma instalação de fontes no Perfex ou sistema operacional, conversão em produção ou download durante a emissão.
- Confirmada a incorporação das três fontes em PDFs sintéticos, com subconjuntos Unicode, acentuação e página A4; mantido o isolamento do TCPDF da aplicação.
- O empacotador exige a validação completa das fontes antes de criar um ZIP, evitando publicar novamente um módulo com recursos tipográficos ausentes ou alterados.
- Corrigida a consulta CNPJ dos parâmetros do emitente para atualizar os dados retornados mesmo quando vieram preenchidos do cadastro da empresa, preservando alterações feitas durante a consulta. Inscrição municipal somente é preenchida quando expressamente retornada.
- Migração `047` sem alteração de esquema. Não modifica menus, ícones globais, cadastros fiscais, certificados existentes ou parâmetros de tributação.

## 0.4.6 — 2026-11-10

- Corrigido o registro da seção nativa em **Configurações → Documentos Fiscais → NFS-e Nacional**: o basename da view agora coincide com o grupo `nfse_nacional`, conforme o comportamento observado no portal. A URL anterior `group=settings_group` continua carregando os assets, exclusivamente na rota de Configurações.
- Links antigos de Configurações são redirecionados para o grupo canônico em GET/HEAD, mantendo o prefixo da empresa e as permissões; POSTs não são redirecionados nem repetidos.
- O defeito deixava a página sem o JavaScript fiscal, impedindo a consulta cadastral e a ponte de salvamento/upload do A1. A correção preserva o formulário nativo, o controller de NFS-e e a separação por empresa.
- Teste de interface passou a exigir os assets emitidos pelos hooks reais e a estrutura `form#settings-form` com multipart, evitando repetir a lacuna da fixture anterior.
- Adicionadas ao runner as verificações de consulta cadastral e upload A1 por HTTP multipart real. Conferidas cifragem, senha vazia/incorreta, titularidade, isolamento e preservação do certificado em falhas.
- Consulta CNPJ, consulta CEP e pesquisa de serviço verificadas no portal instalado, em formulário de tomador não salvo. O resultado está descrito em `docs/TESTES.md`; não equivale a transmissão fiscal ou homologação do A1 real.
- A guia **Faturas e NFS-e** do cliente respeita a disponibilidade do DANFSe. Quando os recursos do PDF estão pendentes, informa o motivo e mantém acesso à nota, sem oferecer um download que falharia.
- Migração `046` sem alteração de esquema. Nenhuma alteração nos estilos, ícones, menus operacionais ou regras de emissão.
- As fontes originais Arial/Microsoft Sans Serif continuam ausentes: este hotfix não declara a geração do DANFSe concluída nem substitui as famílias exigidas.

## 0.4.5 — 2026-11-10

- Perfil de emissão por tomador, com serviço, ISS, tributos aproximados e os parâmetros de IBS/CBS já suportados pelo emissor. Cada campo pode herdar o padrão do cliente/empresa ou armazenar um valor próprio; zero e campo opcional vazio têm significado distinto de herança.
- Aba nativa **Perfil de emissão** no modal do tomador e editor equivalente para os padrões fiscais do cliente. Catálogo de serviços, consultas cadastrais e permissões existentes são reutilizados.
- Ação **Clonar** abre uma cópia editável dos dados e do perfil. Não grava ao clicar; o salvamento cria outro cadastro sem reutilizar ID, vínculos de fatura ou histórico fiscal.
- Preparação pela fatura aplica o perfil completo do tomador. Trocar o tomador no rascunho preenche serviço e tributação do novo perfil; salvar com o mesmo tomador preserva a revisão manual. Mudanças posteriores do cadastro não reescrevem documentos existentes.
- Campos de arquivo e senha do **Certificado A1 local** sempre acessíveis nas configurações. A opção **Usar certificado A1 local** controla somente a prioridade sobre as integrações autorizadas da empresa.
- Leitura adicional de PKCS#12 por OpenSSL em processo isolado, quando a leitura nativa do PHP falha. Compatibilidade exercitada com AES/PBES2, 3DES e RC2, sem alterar o provider da aplicação. O processo usa pipes separados, prazo e limites de saída, sem arquivos temporários ou senha em argumentos. Limites e dependências documentados em `docs/CERTIFICADOS.md`; não se promete suporte universal.
- DANFSe exige Arial regular/negrito e Microsoft Sans Serif originais. Adicionados inspeção de identidade/estilo/permissões técnicas, manifesto, hashes dos recursos e conferência do programa TTF incorporado, além de preparação offline pelo build. Não existe fallback para Liberation Sans ou fontes do Perfex.
- **Pendência de distribuição:** os três originais licenciados ainda não foram fornecidos. Este pacote bloqueia PDF e envio com DANFSe até sua inclusão; a nota autorizada mantém XML e consulta oficial disponíveis e informa a pendência. Testes reais de PDF com as famílias exatas permanecem pendentes.
- Migração `045` sem alteração de esquema. Perfis usam os campos JSON existentes e convertem os valores legados na leitura/salvamento, sem migração em massa dos cadastros. Timestamps reais do ZIP preservados.

## 0.4.4 — 2026-11-10

- Guia NFS-e do cliente organizada nas abas nativas **Dados para emissão**, **Tomadores** e **Faturas e NFS-e**, reutilizando cadastros e modal existentes. Cadastro, edição, exclusão e retorno à aba Tomadores mantêm as permissões atuais.
- Campo **Tomador da NFS-e** na criação/edição da fatura, com pesquisa e opções do cliente selecionado. A preferência é salva em tabela exclusiva do módulo, pelo ciclo nativo de gravação da fatura.
- Primeira preparação/emissão sugere o tomador definido na fatura; o operador pode selecionar outro nesse momento ou revisar o rascunho. Dados fiscais, serviço e descrição acompanham a escolha, preservando o responsável financeiro.
- Mudança de cliente invalida a preferência anterior; respostas atrasadas de consulta são descartadas. Seleções entre clientes/empresas e formulários de outra empresa são recusados. Documentos já preparados não são sobrescritos ao mudar a fatura.
- Gerador do DANFSe incluído no módulo: TCPDF 7.0.22, tc-lib-pdf 8.84.0, dependências, QR Code e Liberation Sans 2.1.5 regular/negrito. Não requer Composer no servidor, instalação de fontes no Perfex ou downloads durante a geração.
- Classes, constantes, configuração e carregamento das bibliotecas de PDF isolados da aplicação. O gerador não utiliza o TCPDF global nem suas fontes, e restringe a leitura de recursos aos arquivos do próprio módulo.
- Fontes Unicode incorporadas ao PDF, preparadas na construção do pacote. TTF originais, licenças, procedência e verificações de integridade acompanham os recursos.
- Diagnóstico de disponibilidade do DANFSe separado da conformidade tipográfica: informa a fonte interna e não solicita instalação manual no Perfex. Liberation Sans continua identificada como família distinta de Arial/Microsoft Sans Serif, prescritas na NT 008.
- DANFSe requer PHP 8.2 ou superior. O ponto de entrada mantém diagnóstico legível em PHP 8.1 antes de carregar as bibliotecas que exigem 8.2.
- A geração real de PDF passa a ser obrigatória nos testes distribuídos. Incluídas verificações de coexistência com classes/constantes do CRM, ausência de escrita nas fontes e tratamento de pacote incompleto.
- Migração 044 cria somente `nfse_nacional_invoice_preferences` no prefixo da empresa; não altera tabelas nativas nem reescreve documentos, tomadores, certificados ou parâmetros fiscais.

## 0.4.3 — 2026-11-10

- Corrigido o empacotamento: a data fixa de 10/11/2026 às 00:00:00 foi substituída pela data/hora UTC real de cada build, única para todos os arquivos e ajustada à precisão de dois segundos do ZIP. Exclusões, organização e modos de arquivo foram preservados.
- Inspeção autenticada identificou a tela nativa de módulos na instalação principal com versão 0.4.2 nos arquivos, enquanto o código executado ainda correspondia à 0.1.0. A repetição dos timestamps é um defeito confirmado no pacote; retenção de código no OPcache permanece hipótese até verificação no portal.
- Hotfix limitado a empacotamento, versão e documentação. Regras fiscais, views, JavaScript, CSS, Multiempresa e patch opcional permanecem como na 0.4.2.
- Migração 043 vazia, sem alteração de tabelas ou dados. A implantação e a validação deste hotfix no portal ainda não foram realizadas.

## 0.4.2 — 2026-11-10

- Menu **NFSE → Documentos** registrado pelas APIs PHP do Perfex. O ícone permanece somente no item principal; o submenu Documentos usa o alinhamento nativo, sem ícone próprio e sem item Configurações.
- Removido integralmente o JavaScript que reescrevia a navegação e os ícones. Os assets antigos de navegação permanecem inertes para que uma referência residual não execute esse reparo.
- A guia NFS-e do cliente usa seu registro PHP nativo. Os assets operacionais são carregados somente nas telas que utilizam o módulo, sem carregamento global de um reparador de menus.
- CSS reduzido a regras internas de `.nfse-root`, sem seletores para menus, fontes ou ícones de outros módulos. Painéis, colunas, formulários, botões, abas e tabelas usam as classes nativas do Perfex.
- Configurações mantidas em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional**, com quatro abas: Emitente, Emissão, Serviço e tributos, Certificado A1. Diagnóstico recolhido no final, sem os cartões de requisitos no topo.
- Blocos condicionais de tomador e tributação já recebem sua visibilidade inicial no HTML do servidor, evitando exibir todos os campos enquanto o JavaScript inicializa.
- Guia de atualização 042 substitui a orientação de menu da versão 041. A cópia privada da empresa deve receber o pacote completo pelo fluxo aprovado existente; não há nova alteração no Multiempresa nesta versão.
- Migração 042 sem alteração de tabelas, certificados, padrões de serviço, numeração, rascunhos ou notas.

## 0.4.1 — 2026-11-10

- Corrigida a identificação da navegação: links `#`, vazios e somente com query string não são resolvidos como rota de NFS-e. Isso impedia que Vendas, Contabilidade, Asaas, Financeiro e outros menus recebessem o ícone fiscal quando a página de notas estava aberta.
- O reparo considera apenas o item principal da NFS-e e a guia fiscal do cliente. Links aninhados em outros módulos e suas classes/ações permanecem intactos.
- NFS-e permanece como link principal único para a gestão. A compatibilidade remove somente seu submenu antigo, inclusive Configurações, seta e handler de expansão. Não são acrescentados ícones a Notas e rascunhos.
- Ícones com a classe nativa `menu-icon` para alinhamento e espaçamento; carregamento em todas as páginas administrativas autorizadas.
- Removido o atalho de configurações da listagem de notas. A parametrização tem acesso em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional**.
- Configurações com abas sem ícones decorativos, campos agrupados, títulos sem duplicação, estado de produção apresentado corretamente desde o HTML e diagnóstico compacto recolhido no final.
- Compatibilidade CSS das verificações antigas em lista, eliminando cartões altos enquanto houver HTML legado.
- Regressão de navegação inclui várias páginas, prefixos de empresa, links genéricos, submenus, conteúdo dinâmico e handlers nativos.
- Patch opcional e verificável para Multiempresa 1.2.4: ação Atualizar módulo, com revisão explícita e reaplicação da cópia da empresa pelo fluxo existente. Não é executado pela instalação da NFS-e.
- Migração 041 sem alteração de banco, certificados, padrões de serviço, rascunhos ou notas.

## 0.4.0 — 2026-11-10

- Configurações no agrupamento nativo **Documentos Fiscais → NFS-e Nacional**.
- Navegação com `fa-solid fa-file-invoice`, validado contra o CSS Font Awesome 6.2.0 publicado na instalação informada; a nova navegação não usa máscara SVG.
- Painéis, filtros, títulos, diagnóstico, formulários e resumos reconstruídos com a estrutura Bootstrap nativa do Perfex. CSS adicional não sustenta o layout principal.
- CSS e JavaScript servidos por rota administrativa da própria empresa, com lista de arquivos permitidos e hash do conteúdo da cópia em execução. Evita PHP privado e assets centrais de versões diferentes.
- Compatibilidade de apresentação e reparo de ícones para views antigas durante atualização gradual.
- Consulta automática de CNPJ e CEP por botão Consultar; reuso do serviço Multiempresa e alternativas públicas em instalações autônomas.
- Preenchimento somente dos campos vazios, proteção contra resposta atrasada, preservação das edições manuais e nenhuma gravação automática.
- Consultas com validação de entradas, destinos fixos, tempo limitado, cache por empresa e limites de uso.
- Migração sem alteração de dados fiscais. Documentado que Multiempresa 1.2.4 não apresenta botão para atualizar módulos ativos e exige reaplicação administrativa da versão aprovada.

## 0.3.1 — 2026-11-10

- Correção específica dos ícones do menu lateral e da guia NFS-e no cliente.
- SVG oficial Font Awesome local com cor herdada do tema, sem depender de fonte, alias ou compatibilidade entre versões da biblioteca.
- Assets mínimos de navegação carregados em toda a área administrativa autorizada, inclusive no dashboard e nas outras guias do cliente.
- Reposição do ícone quando o tema omite o elemento, preservando texto, seta de expansão e links; compatível com navegação inserida por AJAX.
- Migração sem alteração de banco, configurações ou documentos fiscais.

## 0.3.0 — 2026-11-10

- Seção do módulo em **Definições → Configurações → NFS-e Nacional**, com identificadores explícitos e acesso pelo formulário nativo.
- Ícones Font Awesome compatíveis com os nomes utilizados pelo Perfex e pelos módulos fornecidos, inclusive menu e aba do cliente.
- Ação **Emitir NFS-e** visível em cada linha elegível da grade de Faturas, sem depender do hover e sem acrescentar colunas à DataTable.
- Painel em modal a partir da própria linha, com preparação, emissão e histórico; faturas canceladas ou em rascunho oferecem consulta.
- Catálogo nacional pesquisável de serviços (`cTribNac`), com código e descrição oficial; procedência e disponibilidade por ambiente documentadas.
- Serviço e descrição padrão específicos no cadastro de cliente/contato e de cada tomador adicional.
- Preenchimento da nova nota com prioridade tomador → cliente → empresa; descrição dos itens da fatura quando não há texto configurado.
- Troca explícita de tomador no rascunho reaplica seus padrões, mantendo a possibilidade de revisão antes de salvar.
- Validação do catálogo no servidor; códigos exclusivos de testes são identificados e bloqueados em produção.
- Migração preserva os dados existentes; atualização de cadastros não sobrescreve rascunhos ou notas já emitidas.
- Novos testes de catálogo, persistência e seleção de serviço, grade de faturas e configuração por tomador.

## 0.2.0 — 2026-11-10

- Parâmetros centralizados na seção nativa de Configurações, com quatro abas e diagnósticos recolhidos.
- Resolução automática de A1 pelo serviço autorizado da empresa e Multiempresa; prioridade local opcional e extensão interna para núcleo personalizado.
- Validação da empresa, organização, sessão, vínculo do colaborador e identidade do emitente antes de usar o certificado.
- Painel de NFS-e e botão Emitir NFS-e diretamente na prévia administrativa de Faturas; rascunho, situação e histórico acessíveis no mesmo local.
- Atualização do painel após a emissão e atalho complementar no menu nativo da fatura.
- Font Awesome atual, estilos de layout restritos ao módulo e herança de cores, componentes e tipografia do tema.
- Gestão separada de notas emitidas, rascunhos e pendências, com filtros no banco.
- Reaproveitamento dos cadastros e identificação local de município por cidade e UF, sem inventar dados ausentes.
- Bloqueio de nova preparação quando uma fatura muda de cliente após criar histórico fiscal.
- Migração preserva XMLs, sequências e prioridade do certificado local anterior.
- Novas suítes para certificados, hooks, cadastros e vínculo fiscal da fatura.

## 0.1.0 — 2026-11-10

- Primeiro módulo independente NFS-e Nacional para Perfex CRM.
- Configuração de emitente, A1, ambiente, série, serviço e tributação.
- Guia fiscal no cliente, tomadores e dependentes.
- Rascunho e emissão direta da fatura em uma ação.
- Assinatura, esquemas por ambiente, API mTLS e recuperação pela DPS.
- Numeração transacional, proteção contra duplicidade e reenvio explícito do mesmo XML.
- Correspondência do XML recebido com a DPS enviada.
- PDF DANFSe local e envio pelo e-mail nativo.
- Permissões por ação e isolamento no runtime Multiempresa.
- Referência opcional da cobrança Asaas.
- Testes locais e documentação de instalação, API e homologação.

Estado: pacote inicial para homologação; sem emissão real validada nesta entrega.
