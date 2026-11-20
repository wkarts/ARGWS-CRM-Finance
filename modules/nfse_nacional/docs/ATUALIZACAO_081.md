# NFS-e Nacional 0.8.1

Pacote completo para atualização do módulo `nfse_nacional`. Substitua os arquivos na instalação existente sem excluir os dados ou desinstalar o módulo. O Perfex executa a migração 081 e cria somente as tabelas de alíquotas mensais, seleção de clientes/tomadores e fila de emissão. Certificados, documentos e configurações anteriores são preservados.

## Destinatários de e-mail

A guia antes chamada Contabilidade passa a se chamar **Destinatários de e-mail**. A grid permite pesquisar, incluir, editar, cancelar uma edição e remover contatos, seguindo os controles e estilos nativos do CRM. Cada linha é salva individualmente; não depende de Salvar Configurações. Não há limite de dez cadastros nem de dez destinatários selecionados no envio. As restrições efetivas do servidor de e-mail continuam aplicáveis.

Os cadastros existentes mantêm seus identificadores. O envio dos pacotes continua dependendo da seleção explícita dos destinatários; incluir uma pessoa na grid não lhe envia mensagem. São mantidas a proteção CSRF, a permissão de configuração e as validações de e-mail, repetição e caracteres de controle.

## Alíquota variável por competência

Em **Configurações → NFS-e Nacional → Serviço e tributos**, habilite **Usar alíquota cheia variável do Simples Nacional por competência**. O menu **NFSE → Alíquotas por competência** aparece somente enquanto a opção estiver habilitada.

Informe o mês no formato MM/AAAA e a alíquota cheia do Simples, com até duas casas decimais. A competência da prestação salva no documento determina o percentual. Exemplo: documento de 11/2026 utiliza o cadastro de 11/2026, mesmo que emitido em dezembro. Esse percentual corresponde ao campo de tributos do Simples (`pTotTribSN`); não é a alíquota de ISSQN, que permanece independente.

Sem a alíquota do mês, salvar um rascunho continua permitido, mas validar ou transmitir é bloqueado antes da reserva da DPS ou de qualquer requisição externa. A automação aguarda o cadastro do mês e volta a conferir a pendência no cron. Quando a opção está habilitada, o campo das configurações mostra a alíquota do mês atual, somente para leitura. Alterações de cadastro não reescrevem documentos autorizados nem a identidade de uma DPS já reservada/assinada. A alíquota e sua competência são registradas no rascunho emitido.

## Emissão automática

Em **Configurações → Emissão**, escolha:

- **Desabilitado**, **Todos** ou **Selecionados**. O padrão é desabilitado.
- **Após o pagamento integral** ou **Ao criar a fatura**.
- Prazo em minutos, padrão **30**, de **0** (ao final do processamento da requisição) até **43.200**.

Em **NFSE → Automação**, cadastre os clientes e tomadores permitidos no modo Selecionados e consulte a fila. A seleção pode abranger todos os tomadores de um cliente ou um tomador específico. Quando a automação está habilitada, a nova fatura também apresenta a opção **Agendar NFS-e ao criar esta fatura**. Essa seleção agenda a emissão pela criação para aquela fatura, mesmo quando o padrão geral é aguardar o pagamento, permitindo combinar os dois fluxos. A opção não aparece ao editar faturas existentes.

O evento de pagamento é o hook nativo `after_payment_added`; a situação é conferida novamente e somente pagamento integral permite agendamento por esse gatilho. Pagamento parcial não emite. O evento de criação é `after_invoice_added`. Faturas em rascunho ou canceladas não emitem. Não há emissão retroativa em massa de faturas antigas ao habilitar a opção.

A execução usa `after_cron_run` do Perfex. Configure o cron do CRM para rodar regularmente, de preferência a cada minuto. O prazo é o mínimo de espera; a execução ocorre no primeiro cron posterior ao vencimento. Em Multiempresa, o despachante de cron deve executar no contexto de cada empresa; o módulo não percorre bancos de outras empresas nem troca o contexto global.

Antes de transmitir, o worker confere novamente cliente, tomador, valores da fatura, ambiente, emitente, modo da automação, alíquota mensal e existência de documentos. Uma emissão manual, uma tentativa anterior ou um resultado incerto encerram a automação da fatura nesse ambiente. Cancelamento e exclusão da fatura também impedem emissão. Rascunhos criados manualmente ficam para conclusão manual. A fila é única por fatura e ambiente e usa bloqueio de concorrência; uma interrupção após iniciar a transmissão não provoca repetição automática.

A configuração da emissão automática é delegada por um administrador. O cron verifica se esse administrador permanece ativo e administrador. A automação não cria sessão de usuário e recebe acesso restrito à fatura da tarefa. O A1 local ou específico do ambiente pode ser usado sem sessão. O acesso ao A1 central por um serviço Multiempresa que exige sessão interativa continua sujeito a essa exigência; nesse caso é necessário A1 local/específico ou integração de certificado que suporte execução em segundo plano. Não se copia automaticamente o certificado do cofre central.

## Usuário e senha

O cadastro de usuário e senha foi unificado em um único registro criptografado por empresa, apresentado uma vez na guia **Autenticação API**. A sinalização de cadastro vale para os dois ambientes. O usuário precisa cadastrar as mesmas credenciais nos dois ambientes do serviço; o módulo não cria essas contas. Campos vazios preservam o segredo e a exclusão remove as credenciais compartilhadas.

**Limitação funcional:** esta versão não autentica os endpoints nacionais SEFIN/ADN com senha. Os contratos consultados não fornecem um endpoint/fluxo de senha equivalente ao A1 para essas operações. O módulo preserva o A1 e não inventa login, token, sessão ou renovação. Para integrar outra API que aceite usuário/senha, é necessário seu endereço e contrato de autenticação. Não há compatibilidade universal implícita com APIs arbitrárias.

Referências oficiais consultadas em 12/11/2026 (horário da Bahia):

- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita
- https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf

## Validação

Foram executados os fluxos reais do módulo com persistência e HTTP simulados: alíquota mensal ausente, percentual do mês no XML assinado, autorização simulada, fila com espera e deduplicação, faturas canceladas/excluídas, emissão manual, mudança de ambiente/emitente, seleção individual, destinatários acima de dez e cadastro compartilhado de credenciais. Também foram conferidos sintaxe PHP, JavaScript e integridade do pacote.

Não houve instalação no portal, transmissão real, teste SMTP ou execução em banco MySQL do CRM. Os testes DOM não equivalem à homologação visual do tema instalado. O navegador local não iniciou por bloqueio de sockets do ambiente; os testes de interface foram executados em DOM simulado. A suíte herdada contém testes que dependem de processos PHP nativos, sockets e OpenSSL externo e não pode ser integralmente executada no runtime PHP WebAssembly disponível. O teste herdado de PDF com IBPT indisponível também requer revisão independente; a implementação do DANFSe não foi alterada nesta versão.
