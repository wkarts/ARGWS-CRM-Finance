# Atualização 0.8.3

Pacote completo derivado de **nfse-nacional-module-0.8.2.zip**, preservando todos os arquivos e as migrações anteriores. SHA-256 da base: `6419ab0e4cb88d35aa02b50cd28d9b746226957aa5743ba823dc0261760d3199`.

## Instalação

1. Faça o backup habitual do banco, dos arquivos e da chave `APP_ENC_KEY` da instalação.
2. Atualize o código de `modules/nfse_nacional` com o conteúdo deste pacote, incluindo `vendor`. Não desinstale o módulo nem apague arquivos fiscais para atualizar.
3. Execute a atualização de banco oferecida pelo Perfex. A nova migração é a **083**. Ativação e retomada utilizam as mesmas etapas idempotentes.
4. Confira o ambiente ativo e o certificado na guia **Autenticação API**. Se houver certificados antigos diferentes, faça a escolha explícita indicada na tela.
5. Configure as duas automações separadamente e mantenha o cron nativo do CRM ativo. Cada empresa é processada na conexão e no contexto que seu agendador disponibilizar; o NFS-e não percorre empresas por parâmetros de requisição.

## Alterações

| Recurso | Comportamento |
| --- | --- |
| Autenticação | Ambiente e método únicos; A1 local compartilhado com prioridade; aproveitamento do A1 da empresa; credenciais antigas preservadas |
| Senha | Continua sem autenticação operacional nos endpoints nacionais documentados; escolha incompatível retorna limitação explícita |
| Alíquota variável | Alíquota cheia da competência fiscal; cadastro duplicado é recusado; edição explícita; emissão bloqueada sem cadastro do mês |
| Configuração fixa | Preservada ao habilitar a alíquota variável, para utilização quando o recurso for desabilitado |
| Automações | Regras independentes de criação e pagamento integral; modos desabilitado/todos/selecionados por tipo |
| Agendamento | Criação com padrão de 30 minutos; zero significa imediatamente elegível para o cron, sem espera adicional; intervalos menores ou maiores são permitidos |
| Fatura | Mostra as regras aplicáveis ao cliente/tomador; seleção antecipada disponível somente com permissão e regra de criação habilitada |
| Duplicidade | Índices por fatura/ambiente/tipo; bloqueio comum por fatura nas emissões manuais e automáticas; conferência do estado imediatamente antes do POST |
| Recuperação | Timeout ou resposta inconclusiva levam à consulta da DPS; nenhum reenvio automático cego ou nova numeração |
| Gerenciamento | Menu Automações condicional, guias por tipo, situação e motivo; ações de retomar/consultar ou cancelar a pendência, com permissões |
| E-mails | Grid com inclusão/edição/remoção e pesquisa; paginação em 25 linhas; sem limite fixo de dez contatos; IDs e seleção do pacote preservados |
| Sincronização | Solicitação por período ou histórico disponível; fila durável, continuidade automática, pausas do ADN, progresso e retomada |
| Prefixos | Conexão nativa e `db_prefix()` usados literalmente, inclusive vazio; sem adoção automática de `tbl` |
| Multiempresa | Integração opcional; manifesto passa a incluir a nova tabela `nfse_nacional_sync_runs` |

A migração mantém as seleções e pendências da automação anterior no seu tipo original. O segundo tipo inicia desabilitado. Não são agendadas retroativamente todas as faturas antigas ao habilitar uma regra.

## Sincronização e documentos de 2024

O código anterior executava apenas um lote por solicitação e oferecia continuidade manual. A distribuição é percorrida pelo NSU, não por uma seleção de ano. A presença de documentos de 2024 no primeiro lote não comprova ausência de anos posteriores.

A nova fila continua enquanto houver lotes e só conclui após resposta conclusiva de ausência. Respostas 429, erros HTTP e falhas de transporte não são tratadas como fim do histórico. Pausas e tentativas têm agendamento persistido; após cinco falhas consecutivas, a execução fica disponível para retomada pelo usuário.

O endpoint de distribuição implementado não oferece filtro direto de datas. Na modalidade por período, o módulo percorre a sequência necessária e mantém o acervo; o intervalo solicitado é disponibilizado nos filtros de consulta. Assim, não descarta eventos ou documentos de outros períodos necessários à continuidade. Não assume ordem cronológica dos XMLs nem encerra ao encontrar o primeiro ano fora do intervalo.

O cursor, o XML cifrado e os contadores são confirmados na mesma transação. O progresso é recuperado mesmo se o worker parar após arquivar um lote e antes de atualizar a fila. A tela consulta apenas o progresso; fechá-la não interrompe o cron.

O histórico disponível depende da retenção, autorização e documentos oferecidos pela API. Não é possível garantir a recuperação de documentos que o serviço não disponibilize. A causa específica do teste anterior que aparentou retornar apenas 2024 não foi confirmada em uma sincronização real nesta execução; o limite de um lote foi identificado e removido do fluxo solicitado.

## Preservação e escopo

Nenhuma migração anterior foi removida. Documentos autorizados não são recalculados pelas alterações da tabela mensal. Certificados, credenciais, destinatários, preferências, sequências, XMLs e logs anteriores são mantidos. Exclusão de registros da grid exige ação do usuário.

As consultas continuam na conexão corrente do Perfex e nas tabelas do prefixo nativo validado. O prefixo vazio representa tabelas sem prefixo. Identidade, conexão e prefixo não vêm de GET/POST. O cálculo criptográfico anterior permanece compatível. O módulo não cria bancos, não concede privilégios e não reconhece organizações por conta própria.

Consulte [RELATORIO_083.md](RELATORIO_083.md) para os testes executados e suas limitações.
