# Atualização 0.8.5 — alíquotas e permissões

Base: **0.8.4**, conferida contra o pacote publicado mais recente e sua cópia anexada (arquivos idênticos). SHA-256 do ZIP base: `ee6a4922c8559ffe1d6b76307b0b7360d5c50e0e63b5d726a8f06df9af1bac66`.

## Alíquotas e configurações

A grade mensal utiliza linhas renderizadas no servidor, pesquisa e paginação de 25 registros. Deixa de inicializar o DataTables que herdava opções AJAX globais do CRM e mantinha a tabela no estado de carregamento. Os registros já cadastrados continuam na mesma tabela.

Para alterar 11/2026, clique em **Editar** na linha correspondente, altere o percentual e use **Salvar alteração**. O formulário envia a competência original e atualiza o registro existente. A competência permanece fixa durante a edição. **Novo / cancelar edição** volta à inclusão; tentar incluir um mês já existente continua sendo recusado.

Em Configurações, **Percentual do Simples Nacional (%)** mostra o percentual cadastrado para a competência consultada quando a alíquota variável está habilitada. A consulta inicia no mês corrente de America/Bahia e permite selecionar outro mês. Esse seletor serve apenas para consulta; a emissão continua usando a competência fiscal de cada documento.

Somente o percentual do Simples fica em leitura, pois seu cadastro é feito na grade mensal. Permanecem editáveis o método de tributos aproximados (inclusive IBPT), ISSQN, retenção, IBS/CBS e os demais campos. A alíquota mensal não força o método “Percentual do Simples Nacional”. O percentual fixo anterior fica preservado e volta a ser utilizado quando a opção variável é desabilitada.

Mês sem cadastro exibe o aviso correspondente e o percentual vazio. A emissão continua bloqueada para aquela competência até o cadastro, sem recorrer ao percentual fixo, zero ou outro mês. Documentos autorizados e DPS assinadas preservam os valores gravados; editar a tabela não reescreve esses documentos.

## Permissões nativas

Configure as permissões **NFS-e Nacional** no funcionário ou na função do CRM. Para aplicar uma alteração de função aos funcionários existentes, utilize a opção nativa de atualizar os funcionários vinculados. Não existe cadastro paralelo de grupos ou permissões no módulo.

A permissão geral **Visualizar** do módulo é necessária, além das ações específicas:

| Recurso | Permissões disponíveis |
| --- | --- |
| Alíquotas por competência | Visualizar, incluir, editar e excluir, separadamente |
| Automações | Acompanhar filas; gerenciar seleções e pendências |
| Documentos do CRM | Acesso ao relatório/listagem do CRM |
| NFS-e emitidas | Acesso ao relatório nacional de emitidas |
| NFS-e recebidas | Acesso ao relatório de recebidas |
| Pacote mensal | Conferir/preparar o relatório de pacote mensal |
| Arquivos dos relatórios nacionais e pacote mensal | Baixar XML, PDF e pacotes; exige também acesso ao relatório correspondente |

O menu **Relatórios** lista somente os relatórios liberados. Menus, botões e rotas verificam as permissões. Para alíquotas, visualizar não concede escrita, e editar não concede inclusão. Não é mais necessário conceder acesso geral às configurações para delegar a edição de alíquotas. Os menus de alíquotas/automações continuam condicionados à habilitação do recurso.

Administradores mantêm o acesso nativo. **Nenhuma nova permissão é atribuída automaticamente a funcionários ou funções existentes.** Para usuários comuns, o administrador deve conceder as novas permissões. A antiga permissão ampla de consulta nacional não libera silenciosamente todos os relatórios novos.

As operações individuais de fatura, documento, prévia/download do CRM, emissão, transmissão, envio de e-mail e sincronização mantêm seus controles operacionais anteriores. A permissão adicional de arquivos acima trata dos relatórios nacionais e do pacote mensal. A gestão de uma pendência de emissão exige também a permissão operacional de transmissão quando aplicável. Enviar pacote por e-mail continua exigindo sua permissão de envio própria. O pacote mensal possui concessão independente dos relatórios individuais de emitidas/recebidas.

## Preservação e instalação

Atualize o diretório `modules/nfse_nacional` sobre a instalação existente e execute a atualização oferecida pelo Perfex para registrar a migração **085**. Não desinstale para atualizar. Recarregue as telas após a atualização.

A migração 085 não executa SQL, não altera percentuais e não modifica concessões de funcionários/funções. Nenhuma migração anterior foi modificada ou removida. Não foram alterados o escopo criptográfico, as chaves, os certificados, as credenciais, a conexão nativa, a aceitação de prefixo vazio nem as responsabilidades do provisionador. A compatibilidade nativa da 0.8.4 permanece no pacote completo.

## Validação

- 61 suítes PHP aprovadas, incluindo competências, emissão manual/automática, IBPT, permissões, views, hooks e sequência de migrações.
- 9 suítes JavaScript/DOM aprovadas. A nova suíte mensal verifica listagem sem inicializar DataTables, edição do registro, CSRF, alternância dos quatro métodos de estimativa, consulta de outra competência e ausência de percentual sem fallback.
- MariaDB real: quatro cenários de conexão/prefixo, mais dois cenários provisionados usando somente a conexão/prefixo nativos, sem contexto Multiempresa. Cada execução verifica 21 condições, incluindo ativação, isolamento, leitura das credenciais anteriores e retomada de DDL interrompido. Contratos CodeIgniter adaptados para ensaio local.
- MariaDB real: 17 verificações adicionais de listagem/edição da competência existente 11/2026, rejeição de inclusão duplicada, permissões de escrita/relatórios, consulta mensal e preservação independente do percentual fixo e do método IBPT (38 condições acumuladas com a preparação nativa de 21 condições).
- Regressão SQL da sincronização com vários lotes e retomada; emissão concorrente com três workers, duas regras e uma transmissão simulada. Integração opcional de certificados exercitada com o código Multiempresa 1.2.6 de referência.
- Sintaxe dos 591 arquivos PHP e integridade do ZIP conferidas. Todos os arquivos anteriores e os conteúdos das migrações anteriores foram preservados.

**Não executado:** instalação/validação visual no portal do usuário, interface real de funcionários/funções do Perfex, provisionamento no ambiente do usuário, chamadas autenticadas aos serviços nacionais ou SMTP. As interfaces foram exercitadas em DOM local; os testes de banco usam MariaDB real com contratos do CRM adaptados, e os testes fiscais usam transportes simulados. Não representam homologação fiscal em produção.
