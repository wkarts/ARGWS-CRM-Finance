# Correção 0.8.7 — sincronização do acervo nacional

Base: **0.8.6**, versão 6 do pacote publicado. Os 741 arquivos locais foram comparados com o ZIP publicado antes das alterações. SHA-256 da base: `0b1c0b17762799c7cd79bbbb60d1ada23b0d0f4ae3007ee10df7c7e493a979ed`.

## O que mudou nas versões e o que foi reproduzido

| Versão | Comportamento conferido no código |
| --- | --- |
| 0.8.2 | O botão executava `NationalService::sync()` diretamente e importava um lote. O piso de uma hora para HTTP 429 já existia nessa base. |
| 0.8.3 | O botão passou a chamar somente `SyncQueue::start()`, sem executar lote algum. A continuação passou a depender integralmente do worker do cron. Repetir a solicitação de uma execução ativa devolvia a mesma linha, sem reativá-la. |
| 0.8.4 | A correção foi de prefixo/conexão. A fila de sincronização permaneceu igual à 0.8.3. O parser de distribuição também não foi modificado na passagem 0.8.2 → 0.8.3. |
| 0.8.5 e 0.8.6 | As permissões de relatório passaram a ser granulares, mas o worker ainda exigia literalmente a permissão antiga `national_view`, além de `view` e `national_sync`. Um usuário autorizado pela regra atual podia iniciar uma busca que o worker recusava. |

As imagens fornecidas mostram documentos já arquivados, tentativa/conclusão antigas e uma execução agendada com zero itens/lotes. Não mostram um retorno atual da API capaz de provar se há também erro de certificado, transporte ou conteúdo de lote. Portanto, a causa específica de uma resposta real do portal não foi presumida.

Foram reproduzidos localmente: fila pendente sem trabalho, retorno inócuo ao retomar, divergência de permissões do worker, espera excessiva aplicada ao HTTP 429 e seleção de trabalho que precisa ser revalidada sob lock. Não foi aplicado rollback para pacote anterior.

## Comportamento corrigido

1. **Iniciar/Retomar** salva/reativa a execução e processa um lote imediatamente, com a autorização e as fontes de certificado da sessão atual. Não depende de o cron executar para buscar o primeiro lote.
2. A fila continua durável, com processamento pelo cron nativo do CRM. Com a tela aberta, o acompanhamento também solicita lotes adicionais por uma rota POST protegida por CSRF e permissões. O GET de progresso não faz consultas fiscais nem altera agendamento.
3. Ao concluir, a tela atualiza a listagem para mostrar o acervo recebido. Enquanto houver consulta elegível, não é necessário clicar novamente por lote.
4. Retomar uma execução ativa não limpa o NSU, os XMLs, o histórico ou os contadores. Somente a identidade autorizada que retoma pode executar seu processamento interativo; o worker revalida funcionário ativo e permissões atuais no banco.
5. A seleção é relida sob lock e a próxima tentativa é conferida antes de consumir outro lote. Isso impede que uma seleção feita antes de outro worker adiar/concluir a execução provoque nova consulta indevida.
6. HTTP 429 utiliza Retry-After válido, inclusive pausas curtas, ou o fallback conservador existente quando o cabeçalho falta/é inválido. Não é convertido automaticamente em uma hora. A ausência expressa de novos documentos continua impondo ao menos uma hora; pausas já gravadas não são apagadas por Retomar.
7. O worker registra sua última atividade. A tela exibe status legível, quantidade, lotes, último processamento e próxima tentativa; identifica falta de atividade recente. Falhas de certificado, comunicação, sequência, documento ou persistência têm indicação da etapa, sem revelar XML, PFX, senha ou corpo HTTP.

**Com a tela fechada, o cron do CRM precisa estar ativo na conexão/empresa correspondente.** O módulo não cria agendador externo nem percorre organizações/bancos por parâmetros de usuário. Se o cron não executar, o botão continua funcionando e a tela aberta oferece continuidade, mas não se promete processamento em segundo plano inexistente. A resolução de certificados e as integrações opcionais existentes foram preservadas; a rotina precisa de A1 disponível à fonte autorizada usada por cada execução.

## NSU, período e documentos de 2024

A consulta continua usando o endpoint oficial de distribuição por NSU. O cursor só avança após o lote inteiro ser conferido e confirmado na mesma transação dos documentos/contadores. Falha de conteúdo ou banco não descarta lote nem salta o NSU problemático. As chaves únicas continuam prevenindo duplicatas.

Não existe corte em 2024. A ordem do ADN acompanha a recepção, e não necessariamente o ano da emissão. A execução não termina porque encontrou um ano diferente do período solicitado. O teste inclui 2024 → 2025 → 2026 e uma nota de 2024 recebida depois de 2026.

O endpoint usado não oferece filtro de datas do documento: a opção por período mantém a leitura necessária da sequência e usa o intervalo na consulta local do acervo. Emitidas e recebidas são arquivadas conjuntamente e exibidas nas respectivas guias, sem criar faturas.

Referências conferidas: [Manual das APIs do ADN para contribuintes](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-apis-adn-sistema-nacional-nfse.pdf) e [FAQ oficial, itens 6.7 e 19.1–19.3](https://www.gov.br/nfse/pt-br/perguntas-frequentes/perguntas-frequentes-nfs-e-v1-3-20261106.pdf). A disponibilidade depende do compartilhamento, autorização e retenção do serviço. Não há garantia de recuperar documentos que a API não disponibilize.

## Atualização e preservação

Atualize `modules/nfse_nacional` sobre o diretório existente e execute a atualização de banco indicada pelo Perfex. Não desinstale nem limpe tabelas para atualizar. Na guia Emitidas ou Recebidas, use **Retomar sincronização**, mantendo o ambiente e o modo da execução existente.

A migração **087** acrescenta somente `last_worker_at DATETIME NULL` à tabela `nfse_nacional_sync_runs` da conexão/prefixo nativos. Detecta a coluna existente, relata falha de DDL e permite repetir a atualização. Não altera registros fiscais, NSU, agendamento já gravado, criptografia, credenciais ou certificado. O instalador também inclui a coluna para instalações novas.

Todas as migrações anteriores e todos os arquivos anteriores permanecem no pacote. Os recursos da 0.8.6, incluindo alíquotas mensais de Simples/ISSQN e permissões granulares, foram preservados. O Multiempresa não foi modificado, nem foram criadas configurações, segredos ou responsabilidades de provisionamento.

## Validações executadas

| Validação | Resultado e limites |
| --- | --- |
| Suítes PHP | 62 aprovadas; inclui serviço nacional, XML/NSU, isolamento, alíquotas, emissões, permissões e migrações. |
| Distribuição | 162 verificações; cabeçalhos Retry-After de 5/0/120 segundos, ausência confirmada, erro/ambiente, sequência e XML. Transporte simulado. |
| Serviço nacional | 48 verificações; preservação transacional, emitidas/recebidas, cursor, eventos, pausa curta de 429 e uma hora somente para ausência confirmada. Sem rede fiscal. |
| Interface | 10 suítes JavaScript/DOM aprovadas, incluindo 14 verificações de progresso, continuidade POST/CSRF, pausa, acesso somente de leitura e conclusão. Views/JS reais em jsdom; rede simulada. |
| Matriz SQL | MariaDB real: Perfex nativo com/sem prefixo, contexto opcional de banco exclusivo sem prefixo e banco compartilhado com prefixo; 21 verificações por cenário. Contratos CodeIgniter adaptados. |
| Regressão da busca | 46 condições acumuladas em MariaDB: fila parada, endpoint real Iniciar/Retomar, GET sem transmissão, POST de continuidade, worker sem sessão com permissões atuais, notas de 2025/2026 e recebidas, continuidade após 2024 tardio, pausa preservada, seleção concorrente revalidada, revogação e retomada de migração interrompida. Certificado sintético e API simulada. |
| Demais regressões SQL | Alíquotas Simples/ISSQN; sincronização com falha/retomada; dois tipos de emissão e três workers concorrentes, com uma transmissão simulada. |
| Sintaxe e pacote | 596 arquivos PHP conferidos; integridade do ZIP e conteúdo das migrações anteriores comparados com a 0.8.6. |

**Não executado:** conexão autenticada ao ADN/SEFIN com o certificado do usuário, instalação no portal, inspeção/configuração do cron real das empresas, teste visual no navegador do portal ou confirmação de que todos os documentos visíveis no Portal Nacional estão acessíveis à API para esse certificado. Não foram acessados bancos, certificados ou credenciais de produção. Os testes do worker demonstram o processamento sem sessão no ensaio local; não comprovam o acionamento do agendador do ambiente do usuário.
