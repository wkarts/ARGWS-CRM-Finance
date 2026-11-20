# Logs operacionais da NFS-e

Disponível a partir da versão **0.4.8**. O histórico pertence ao próprio módulo,
sem dependência do Asaas e sem mudanças nas tabelas nativas do Perfex.

## Acesso e uso

Abra **NFSE → Logs**. Também há um atalho em **Documentos** e em
**Configurações → Documentos Fiscais → NFS-e Nacional → Verificações da instalação**.

Administradores podem acessar os registros. Para outros colaboradores, são
necessárias as capacidades **Visualizar NFS-e** e **Visualizar e exportar logs
da NFS-e da empresa ativa**, nas permissões nativas. Permissão de configuração,
emissão ou edição não concede automaticamente acesso ao diagnóstico.

Filtros disponíveis: datas inicial/final, nível, operação, ambiente, status HTTP,
pesquisa textual e referência. Cada tentativa recebe uma referência única;
início, chamadas ao ambiente nacional e conclusão/falha compartilham esse valor.
Erros devolvem a referência à interface e, quando permitido, o botão
**Ver detalhes no log**. A tela de detalhe permite consultar os eventos relacionados e usar **Baixar diagnóstico desta operação**. A listagem possui o botão **Baixar diagnóstico detalhado (JSON)**, com os filtros atuais, e um ícone compacto de download por registro. Nenhuma janela modal é aberta.

O histórico começa após a instalação desta versão. Não é possível recuperar
retroativamente o corpo ou os detalhes de uma resposta antiga que não foi registrada.

## Operações acompanhadas

| Grupo | Eventos |
|---|---|
| Configuração | Salvamento dos parâmetros, importação/substituição do A1 no mesmo formulário, remoção do certificado local |
| Cadastro | Consulta de CNPJ/CEP, gravação do perfil fiscal do cliente, gravação e remoção de tomadores |
| Rascunho | Preparação pela fatura, edição, validação e exclusão |
| Emissão | Emissão pela fatura, transmissão, consulta da DPS e reenvio da mesma DPS |
| API nacional | Cada tentativa de consultar convênio, transmitir DPS, consultar DPS ou obter NFS-e |
| Documento | Download de XML e DANFSe, envio e nova tentativa de e-mail |
| Diagnóstico | Exportação JSONL e relatório detalhado JSON (operação ou filtros) |

As chamadas às APIs nacionais registram serviço, ambiente, método, URL com
identificadores substituídos, HTTP, duração, tamanho e SHA-256 dos corpos,
tipo de resposta e mensagens de erro tratadas. O resultado é registrado depois
da validação do conteúdo, evitando declarar autorização apenas por receber HTTP 200/201.

A partir da **0.7.9**, a consulta de distribuição ADN registra também etapa de validação, situação de processamento da resposta, quantidade de itens do lote e quantidade de datas auxiliares `DataHoraGeracao` recuperadas. Quando a falha ocorre no cURL, acrescenta código numérico da falha e categoria (`dns`, `connect`, `timeout`, `tls_handshake`, `tls_verification`, `certificate`, etc.), além de tempos de resolução DNS, conexão TCP, handshake TLS e primeiro byte, quando disponíveis. Mensagens brutas do transporte não são armazenadas por poderem conter credenciais e caminhos locais.

O log de consulta cadastral registra o resultado da operação e os nomes/quantidade
de campos encontrados. Ele não copia os valores pessoais retornados nem o histórico
interno de provedores de uma integração existente. Operações de e-mail registram
a conclusão ou falha do fluxo do módulo; a aceitação SMTP não comprova a entrega
na caixa do destinatário.

## Consulta municipal e HTTP 404

A consulta de convênio utiliza **ADN — Parâmetros Municipais**. A lista oficial
de APIs separa esse serviço da SEFIN:
<https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao>.

A versão anterior rotulava a resposta como erro da SEFIN e ignorava o campo
textual `mensagem` do retorno municipal. Agora a origem correta e a mensagem
disponível são preservadas. Um 404 permanece **consulta inconclusiva**: isoladamente,
não prova ausência de convênio, nem permite concluir que o município não pode emitir.

Os detalhes distinguem retorno JSON, HTML, XML, vazio, inválido, acima do limite
e falha de transporte. Não há mudança automática de ambiente, endpoint ou certificado
para contornar uma falha. Se o emitente ou certificado estiver inválido, a falha
local é registrada antes da chamada externa.

Na consulta da DPS, somente uma resposta expressa de ausência relacionada ao
documento pode liberar o fluxo existente de reenvio da mesma DPS. Um 404 de rota,
gateway, corpo genérico ou vazio mantém o resultado incerto e impede novo POST.

## Proteção e isolamento

O escopo é obtido exclusivamente do contexto autenticado da empresa, incluindo
o vínculo do colaborador no Multiempresa. Nenhum filtro, ID ou parâmetro enviado
pelo navegador escolhe outra empresa ou arquivo. Listagem, detalhe e exportação
verificam a mesma permissão e usam o mesmo escopo.

Não são persistidos corpo bruto, XML fiscal, Base64 de documentos, arquivos A1,
senhas, chaves privadas, cabeçalhos de autenticação ou tokens. O contexto segue
uma lista fechada de campos; mensagens recebem limitação e mascaramento de
identificadores, e-mails, telefones e padrões de credenciais. A leitura reaplica
essa proteção, inclusive para registros antigos ou alterados no servidor.

O histórico é operacional e não substitui os XMLs autorizados, os eventos fiscais
do documento ou um sistema externo de auditoria imutável.

## Armazenamento, retenção e exportação

O módulo cria arquivos protegidos no `log_path` configurado pelo Perfex, com
fallback para `APPPATH/logs/`, em subdiretórios isolados por empresa. Cada segmento
começa com uma instrução PHP de encerramento; diretórios e arquivos usam permissões
restritas. Links simbólicos e caminhos fora do contrato são recusados. A gravação
usa lock e nunca repete uma ação fiscal quando o armazenamento falha.

| Limite | Valor |
|---|---:|
| Retenção máxima | 30 dias; segmentos mais antigos podem sair antes pelo limite de quantidade/tamanho |
| Segmentos por empresa | 10 |
| Tamanho por segmento | 2 MiB |
| Total de eventos por empresa | Até 20 MiB |
| Tamanho de um evento | 16 KiB |
| Página da interface | 50 registros |
| Download filtrado | Até 2.000 registros ou 4 MiB |
| Espera por lock | Até 200 ms |

A rotação e remoção dos segmentos vencidos ocorrem nas gravações. Registros fora
da retenção não aparecem nas leituras. Datas de filtro e exibição seguem o fuso
da aplicação; os arquivos armazenam horário ISO em UTC.

O novo **download detalhado** (versão 0.7.9) é JSON formatado (`nfse-diagnostico-*.json`) e apresenta:

- Versão do módulo, instante UTC, indicador de truncamento e retenção.
- Resumo de ocorrências por operação, ambiente, HTTP, severidade e etapa; durações média e máxima.
- Sequência cronológica completa dos eventos de uma mesma referência, ou todos os eventos que atendem aos filtros.
- Dados permitidos de cada evento: host e caminho de API sem query e sem identificadores fiscais, status, tamanhos e hash SHA-256 do payload, diagnósticos de transporte e causas tratadas de exceção.
- Notas de interpretação do HTTP 0, HTTP 2xx com erro posterior e uso de data recuperada do XML.

As exportações são de leitura, não alteram os documentos, não executam novamente a consulta nem exigem fechar avisos. Ambas obedecem ao limite de 2.000 eventos ou 4 MiB de registros-base e à retenção. Dados gravados antes da 0.7.9 não passam a conter tempos detalhados retroativamente.

O download é JSON Lines (`.jsonl`), uma estrutura JSON por linha, sem cabeçalho PHP
ou caminhos físicos. Os filtros são preservados; os cabeçalhos `X-NFSe-Log-Records`
e `X-NFSe-Log-Truncated` informam quantidade e limitação. A interface apresenta
os limites, e leituras incompletas exibem aviso. Não há ação de exclusão manual.

Se o diretório não puder ser lido, a tela informa indisponibilidade. Se uma escrita
falhar, a operação fiscal prossegue e a referência só é anunciada se ao menos
um evento daquela tentativa tiver sido persistido.

## Verificação

- `tests/operational_log_test.php`: proteção, concorrência, retenção, exportação,
  contexto autenticado, isolamento e falhas de armazenamento.
- `tests/log_views_test.php`: estados, escape de conteúdo, filtros, paginação e horários.
- `tests/log_controller_test.php`: rotas, permissões, correlação, resposta, exportação detalhada, sanitização e isolamento.
- `tests/sdk_test.php` e `tests/service_test.php`: contrato municipal, observador
  HTTP, 404 inconclusivo e bloqueio de reenvio indevido.

As simulações usam certificados e documentos sintéticos. Uma resposta real do
município depende dos parâmetros e certificado válidos da empresa no ambiente escolhido.
