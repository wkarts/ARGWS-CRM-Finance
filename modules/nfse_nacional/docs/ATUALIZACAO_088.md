# Correção 0.8.8 — bloqueio HTTP 409 na retomada

Base: **0.8.7**, versão 7 do pacote publicado, comparada byte a byte com o código local antes das alterações. SHA-256 da base: `d1b64eb32d567c2e4a515ab6d039eee93573195e01efba445759b78540f0196c`.

## Evidência e reprodução

O diagnóstico enviado registra `module_version=0.8.7` e uma tentativa `national.sync` encerrada em aproximadamente 13 ms com HTTP **409**:

> Já existe uma sincronização ativa neste ambiente. Aguarde a conclusão para iniciar outro período.

A operação foi interrompida em `SyncQueue::start()`, antes da chamada de distribuição. Os dois eventos enviados são de início/fim dessa operação; não mostram uma resposta do ADN nessa tentativa. A versão 0.8.7 ainda recusava uma retomada quando o modo ou as datas solicitados diferiam dos valores guardados na execução ativa. O diagnóstico não contém os valores antigos/novos desses três campos; não foi presumido qual deles estava diferente.

Foi acrescentado um teste que mantém uma fila pendente por período de 2024 e aciona o endpoint real pedindo histórico completo. **Antes da alteração, o ensaio reproduziu HTTP 409 e a mesma mensagem**, sem consumir a página seguinte. Após a alteração, o mesmo endpoint retorna 200 e consulta o último NSU confirmado, importando o lote seguinte sem voltar ao início.

## Alteração pontual

As opções de período e histórico completo utilizam a mesma sequência de distribuição do ADN. A diferença entre seleções não exige outra fila nem outra sequência. Ao retomar uma fila ativa, agora são atualizados somente os metadados da seleção, o usuário autorizado para retomada e o agendamento, dentro do lock existente.

- Permite alternar período → histórico, histórico → período ou datas de um período ativo.
- Normaliza as datas de histórico completo para `NULL`, inclusive quando restaram datas de uma seleção anterior.
- Preserva o NSU, os documentos/XMLs, os contadores, as referências iniciais dos contadores e o horário original da execução ativa.
- Mantém as pausas impostas pelo serviço e a proteção de concorrência. Não ignora um worker realmente processando sob lock.
- Rejeita datas novas inválidas antes de alterar a fila.
- Não apaga filas, não zera tabelas e não cria uma nova emissão ou uma nova fatura.

O primeiro lote imediato, a continuação da tela por POST/CSRF, o cron, os diagnósticos e todos os demais recursos da 0.8.7 permanecem no pacote.

## Atualização

Atualize o diretório `modules/nfse_nacional` sobre a instalação existente e execute a atualização oferecida pelo Perfex para registrar a versão **088**. Não desinstale e não limpe o histórico.

Recarregue a página, escolha **Todo o histórico disponível** e clique em **Retomar sincronização**. A fila existente será retomada do cursor salvo. Para continuar com a tela fechada, o cron nativo continua sendo necessário. Não é necessário remover a fila antiga nem refazer o cadastro do certificado.

A migração **088** é vazia: não executa SQL, não altera credenciais, criptografia, banco/prefixo ou documentos. Todas as migrações anteriores permanecem intactas. O Multiempresa e o provisionador não foram modificados.

## Validação

- Regressão em MariaDB real, com contratos CodeIgniter adaptados: **53 verificações acumuladas**, incluindo endpoint de retomada, preservação do NSU/contadores/início, alternância entre seleções ativas, datas residuais de histórico completo, período inválido, pausa mantida após troca de seleção, emissão/recebimento de documentos e retomada de migração interrompida. API simulada e certificado sintético.
- **62 suítes PHP** aprovadas e **10 suítes JavaScript/DOM** aprovadas.
- Sintaxe PHP, integridade do ZIP e preservação das migrações anteriores conferidas.

**Não executado:** instalação no portal do usuário ou chamada autenticada ao ADN com seu certificado. A correção elimina o HTTP 409 reproduzido a partir do diagnóstico; não equivale a afirmar que uma consulta real posterior ao ADN já foi validada. Se o serviço devolver outra falha, ela deve ser identificada pelo novo evento da tentativa, sem apagar o cursor para contorná-la.
