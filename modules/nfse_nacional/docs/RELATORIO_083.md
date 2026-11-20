# Relatório de alterações e validação — NFS-e Nacional 0.8.3

Data: 13/11/2026. Base de desenvolvimento: pacote completo 0.8.2. O pacote foi atualizado sobre essa base; nenhum arquivo ou migração anterior foi removido.

## Resultado das alterações

- Autenticação simplificada: um ambiente ativo, um método, um A1 local e um cadastro compartilhado de usuário/senha. Há prioridade para o A1 local e aproveitamento automático do certificado autorizado da empresa.
- Migração não destrutiva dos perfis anteriores. Certificados diferentes exigem escolha explícita; logins distintos permanecem protegidos. O contexto e o algoritmo de criptografia existentes foram mantidos.
- Alíquota cheia pela competência fiscal, com inclusão/edição distintas, unicidade e bloqueio manual/automático quando falta o cadastro. A configuração fixa permanece preservada para quando o recurso for desabilitado.
- Automações independentes após criação e após pagamento integral. Seleções próprias por tipo, acompanhamento em guias, ações autorizadas e menu condicional.
- Bloqueio comum por fatura para os fluxos manual e automático, consulta de DPS inconclusiva, encerramento das demais pendências após autorização e revalidação imediatamente antes do POST.
- Grid de destinatários preservada e ampliada com paginação e pesquisa global, sem limite fixo de dez cadastros.
- Sincronização durável, com histórico completo/período, continuidade dos lotes, pausas, tentativas e progresso recuperável a partir do cursor confirmado.
- Prefixo vazio aceito na conexão nativa do Perfex. Validações de prefixos preenchidos e isolamento continuam ativos. Nova tabela declarada no manifesto opcional do Multiempresa.

## Validações executadas

| Área | Resultado e evidência |
| --- | --- |
| Regressão PHP | 61 suítes aprovadas, incluindo emissão, API, certificados, XML, IBPT, PDFs/fontes, permissões, CSRF, migrações, destinatários, alíquotas e filas |
| Interface | 8 suítes JavaScript/DOM aprovadas, com views PHP reais e transporte simulado |
| Sintaxe | 586 arquivos PHP aprovados; bundle JavaScript validado |
| Sequência de migrações | 151 verificações; migrações anteriores e nova 083 presentes, sem lacunas |
| Banco real | MariaDB 11.8.6 com adaptador de contratos CodeIgniter; quatro cenários de conexão/prefixo, 17 verificações por cenário |
| Cenários de banco | Perfex independente com prefixo e sem prefixo; contexto Multiempresa com banco exclusivo sem prefixo e compartilhado com prefixo |
| Retomada da instalação | Falha de DDL induzida na atualização, seguida de repetição e conclusão; configurações e credenciais anteriores preservadas |
| Credenciais antigas | Leitura de envelopes anteriores com o contexto criptográfico original nos quatro cenários; logins diferentes preservados |
| Ponte Multiempresa | 31 verificações de certificados: 19 de resolução e 12 com código real do Multiempresa 1.2.6 e SQLite, incluindo autorização, auditoria e recusa de credenciais de outra empresa |
| Alíquota mensal | Bloqueio sem cadastro; 6,25% da competência aparece como `pTotTribSN` no XML assinado em teste com API simulada; ausência não gera POST |
| Automação | 25 verificações do worker: prazo, seleção, competência ausente, quitação integral, cancelamento/exclusão, emissão manual e consulta após retorno incerto |
| Concorrência | Três processos PHP, conexões independentes e bloqueios/índices reais do MariaDB; duas regras para a mesma fatura resultaram em uma única chamada externa simulada |
| Sincronização | Lotes sintéticos de 2024, 2025 e 2026, falha intermediária, retomada pelo cursor confirmado, ausência conclusiva, XML recuperável e filtro do período solicitado |
| Interrupção após commit | Contadores da fila deliberadamente zerados após o commit de um lote; progresso recuperado dos contadores transacionais do acervo |
| Destinatários | Preservação de 15 contatos antigos na migração; testes com 25 registros no cadastro e 61 contatos na grid paginada, incluindo busca fora da primeira página |

Os testes SQL de sincronização executam a fila, serviço, repositório, criptografia e resolução local reais do módulo; somente a API externa é substituída. Os testes de concorrência usam o worker real e substituem a transmissão remota. Os testes do serviço fiscal exercitam assinatura e conferência de XML com respostas sintéticas.

## Verificações não executadas e limites

- Não houve instalação, atualização, ativação/desativação ou navegação no portal Perfex do usuário. Os testes de instalação/migração usam banco real e contratos do CRM adaptados.
- Não houve transmissão, consulta ou sincronização autenticada contra SEFIN/ADN em produção ou produção restrita. Certificados dos testes são sintéticos, sem acesso a certificados ou credenciais de produção.
- A documentação oficial foi consultada. Os Swaggers dos dois ambientes não puderam ser abertos diretamente nesta execução. Não foi confirmado um contrato operacional de API por usuário/senha para os endpoints utilizados; esse método retorna a limitação explícita, sem simular sucesso.
- Não houve envio real de e-mail por SMTP. A seleção dos destinatários, arquivos, CSRF e invocação do envio foram testadas com o transporte substituído.
- O Chromium local não iniciou por restrição do ambiente a sockets Unix. A validação de interface foi realizada por DOM; a inspeção visual no portal permanece pendente.
- A execução sem tela depende do cron nativo estar ativo e do certificado estar acessível em segundo plano. O A1 local atende a essa condição; provedores de A1 da empresa precisam oferecer acesso autorizado sem sessão interativa.
- A consulta ADN implementada é sequencial por NSU. O período solicitado é aplicado à consulta do acervo, após percorrer os lotes necessários. A disponibilidade e retenção dos documentos dependem do serviço oficial.
- A causa específica da listagem anterior aparentemente restrita a 2024 não foi confirmada no ambiente do usuário. Foi identificado o processamento de apenas um lote por solicitação e substituído por continuidade automática. O teste de múltiplos anos confirmou a nova regra localmente.

## Como reproduzir

- `PHP_BIN=/caminho/php tools/test.sh`: regressão PHP; PHP 8.2+ é necessário para os gates do DANFSe.
- Para os testes SQL, use uma instância local descartável de MariaDB e informe `NFSE_TEST_MYSQL_PORT`. As bases de teste possuem nomes `nfse_test_*` e são limpas pelos testes. Não execute esses testes em um banco de produção.
- Para validar a ponte, informe `NFSE_MULTIEMPRESA_ROOT` com o código do Multiempresa 1.2.6 e habilite PDO SQLite.
- As suítes `tests/*_test.js` precisam de Node.js e jsdom. Usam as views do pacote; não requerem instalar dependências JavaScript no CRM.

Instruções de atualização e comportamento operacional: [ATUALIZACAO_083.md](ATUALIZACAO_083.md).
