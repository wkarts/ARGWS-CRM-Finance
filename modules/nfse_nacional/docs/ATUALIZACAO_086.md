# Recurso 0.8.6 — alíquotas mensais de Simples Nacional e ISSQN

Base: pacote publicado **0.8.5**, versão 5, conferido byte a byte com a cópia local antes das alterações. SHA-256 da base: `635b3fb1783c64aae615130bf775a2e9fcbbeea36dc6085b6ead1b828b3d6659`.

## Configuração

Abaixo da opção existente de alíquota cheia variável do Simples, foram acrescentadas:

1. **Usar alíquota variável do ISSQN por competência**.
2. **Utilizar no ISSQN a mesma alíquota cadastrada para o Simples Nacional**.

A primeira opção é independente da opção de Simples. A segunda escolhe de qual cadastro mensal virá o ISSQN; só é aplicada quando o ISSQN variável estiver ativo.

| Simples variável | ISSQN variável | Compartilhar | Aplicação |
| --- | --- | --- | --- |
| Desligado | Desligado | Qualquer | Percentuais fixos existentes |
| Ligado | Desligado | Qualquer | Simples mensal e ISSQN fixo |
| Desligado | Ligado | Desmarcado | Simples fixo e ISSQN mensal próprio |
| Ligado | Ligado | Desmarcado | Dois percentuais mensais separados |
| Ligado | Ligado | Marcado | O percentual mensal do Simples preenche ambos |
| Desligado | Ligado | Marcado | O cadastro mensal do Simples é utilizado somente no campo ISSQN; o campo Simples permanece fixo |

O seletor **Competência consultada** permite conferir os campos resultantes nas configurações. Somente cada percentual ativado como variável fica em leitura; os demais controles, inclusive IBPT, tributos aproximados, retenção e IBS/CBS, permanecem editáveis. Ao desabilitar uma opção, seu percentual fixo anterior volta ao campo e não é sobrescrito pela consulta mensal.

## Cadastros na mesma tela

Em **NFSE → Alíquotas por competência**, há duas guias:

- **Simples Nacional**: preserva o cadastro e os percentuais existentes.
- **ISSQN**: cadastro próprio para percentuais separados.

Cada guia oferece inclusão, edição, exclusão, pesquisa e paginação. A mesma competência pode existir nas duas guias com valores diferentes, mas não pode ser duplicada no mesmo cadastro/emitente. As quatro permissões nativas de alíquotas da 0.8.5 continuam sendo utilizadas, por funcionário ou função. O menu aparece quando ao menos uma modalidade variável está habilitada e o usuário possui permissão de consulta.

O compartilhamento consulta diretamente o cadastro do Simples, sem copiar registros para a guia ISSQN. Desmarcá-lo preserva os dois cadastros e passa a utilizar o registro próprio de ISSQN. Nenhum registro antigo é reinterpretado como ISSQN durante a atualização.

## Aplicação fiscal e limite existente

O mês fiscal do documento determina cada consulta, também na emissão automática. Se algum percentual necessário não estiver cadastrado, a preparação indica a pendência e a validação/transmissão bloqueia a emissão. Não se utiliza percentual fixo, zero ou mês vizinho como alternativa silenciosa.

Quando compartilhado, o percentual é integral: por exemplo, **3,25% → 3,25% nos dois campos**, sem multiplicador, redução ou transformação. O compartilhamento é opcional e não altera as regras de preenchimento de `pAliq` já existentes para regime, tributação e retenção.

**A validação existente de ISSQN de até 5% permanece aplicável.** O exemplo de **11,41%** do Simples pode continuar cadastrado como Simples, mas não pode ser transmitido como alíquota de ISSQN. Nesse caso o recurso exibe o motivo e exige selecionar o cadastro separado de ISSQN. Não reduz o percentual para 5%, não converte pela parcela do ISS e não troca a opção escolhida automaticamente.

Referências conferidas: [documentação atual nacional — Anexo I](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual) e [tabela de validações disponibilizada pela Prefeitura de Ji-Paraná, regra 501/E0595](https://certidoes.ji-parana.ro.gov.br/issweb/temp/ANEXO_~1.pdf).

O rascunho registra competência, percentual e origem do Simples/ISSQN utilizados. Documentos autorizados e DPS assinadas não são reescritos por alterações posteriores da tabela. Os testes de transmissão verificam os percentuais no XML assinado e impedem nova transmissão do documento já autorizado.

## Migração e preservação

A migração **086** cria apenas `db_prefix() . nfse_nacional_competence_iss_rates`, usando a conexão corrente do CRM e chave única de emitente/competência. É repetível com `CREATE TABLE IF NOT EXISTS`, relata falha e permite nova execução. O instalador inclui a nova estrutura, e o manifesto opcional de integração declara a tabela. Não são criados bancos, privilégios, segredos ou responsabilidades de provisionamento.

O ISSQN variável e o compartilhamento começam desativados nas instalações anteriores. Permanecem preservados os percentuais fixos, registros do Simples, credenciais, certificados, escopo criptográfico, permissões, automações e sincronizações existentes. Nenhuma migração anterior foi modificada ou removida.

Atualize o diretório `modules/nfse_nacional` sobre o existente e execute a atualização de banco indicada pelo Perfex. Não desinstale. Em seguida escolha as opções desejadas e cadastre a guia necessária.

## Validações

- **62 suítes PHP aprovadas**, incluindo 28 verificações específicas dos modos independentes/compartilhados, preservação de fixos, ausência por competência, origem dos snapshots e bloqueio de valores inválidos.
- Fluxo real do serviço, assinatura e XML: **133 verificações**, incluindo ISSQN sozinho, ambos separados e ambos compartilhados, registro ausente, snapshot e imutabilidade após autorização. Persistência em memória, certificado sintético e transporte fiscal simulado.
- Worker real de automações: **30 verificações**, incluindo bloqueio de ISSQN ausente antes de preparar/transmitir, liberação após cadastro e bloqueio de compartilhamento acima do limite. Sem rede fiscal.
- **9 suítes JavaScript/DOM aprovadas**, incluindo 28 verificações da grade, edição com identificação do tipo, campos independentes, compartilhamento, consulta dos dois percentuais e mês sem cadastro. Views e JavaScript de produção em jsdom; requisições simuladas.
- MariaDB real com contratos CodeIgniter adaptados: quatro cenários de conexão/prefixo com 21 verificações cada; ensaio de alíquotas com **51 verificações acumuladas**, incluindo CRUD dos dois cadastros, unicidade, permissões, separação por emitente, interrupção/retomada da migração 086 e preservação do Simples.
- Regressão SQL da sincronização com vários lotes e retomada e da emissão com três workers concorrentes, preservada uma transmissão simulada.
- Sintaxe PHP, integridade do ZIP e preservação byte a byte das migrações anteriores conferidas.

**Não executado:** instalação e validação visual no portal do usuário, homologação contra a SEFIN/ADN autenticada, certificados reais, SMTP ou provisionamento real das empresas. Os ensaios não representam autorização fiscal em produção.
