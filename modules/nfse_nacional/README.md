> Versão 0.8.9: [correção da leitura de eventos históricos do ADN](docs/ATUALIZACAO_089.md). Aceita o número do registro `nDFe`/`nDFSe`, preserva XML, cursor, recursos e migrações anteriores.

# NFS-e Nacional para Perfex CRM

**Inclui busca de NBS por código ou descrição utilizando exclusivamente a tabela IBPT local da UF do emitente, preservando a sincronização ADN e os logs detalhados da 0.7.9.**

**Desenvolvedor:** WWSoft -> Wallace Kleiton. O crédito é exibido automaticamente pelo Perfex, mediante os campos `Author` e `Author URI` no arquivo principal `nfse_nacional.php`. A referência ao SDK Asaas permanece exclusiva do módulo Asaas.

Módulo PHP nativo para Perfex CRM 3.2.x. Emite NFS-e diretamente na SEFIN Nacional com certificado A1. Usa a fatura como origem da operação e mantém o tomador fiscal separado do responsável financeiro.

As validações locais e a conferência do portal estão descritas em [TESTES.md](docs/TESTES.md). Os perfis fiscais reutilizam os cadastros de clientes e tomadores, com herança por campo e aplicação no rascunho. Os ensaios do DANFSe usam XMLs sintéticos; não representam homologação fiscal com certificado ICP-Brasil real ou contra o ambiente nacional autenticado.

**Fontes prontas no pacote:** os três arquivos originais fornecidos pelo usuário foram preparados e incluídos em `vendor/pdf-runtime/fonts/nt008/`. O DANFSe utiliza esse conjunto diretamente, sem instalar fontes no Perfex ou no sistema operacional e sem downloads durante a geração. Consulte [DANFSE_EMBUTIDO.md](docs/DANFSE_EMBUTIDO.md).

### Autenticação da API

Um ambiente ativo, um método e um cadastro compartilhado de A1 e de usuário/senha. O A1 local tem prioridade; na ausência dele, aproveita o certificado autorizado da empresa. Cadastros antigos diferentes são preservados e a escolha do A1 compartilhado é explícita quando há conflito. A senha do portal não é apresentada como autenticação funcional dos endpoints SEFIN/ADN. Consulte [AUTENTICACAO_API.md](docs/AUTENTICACAO_API.md).

### Automações, alíquotas e sincronização

As opções de alíquota mensal do Simples e do ISSQN são independentes. É possível aplicar somente uma, ambas com percentuais próprios ou compartilhar explicitamente o cadastro do Simples com o ISSQN. As guias Simples Nacional e ISSQN ficam na mesma tela de competências. Os demais tributos e o método de estimativa permanecem livres; o compartilhamento respeita as validações fiscais existentes do ISSQN e não converte percentuais. Permissões independentes de consulta, inclusão, edição e exclusão; relatórios e automações também podem ser delegados nas permissões nativas de funcionários e funções do CRM. Novas permissões precisam ser concedidas pelo administrador.

As regras após criação e após pagamento integral são independentes e podem atuar simultaneamente. Cada tipo tem sua seleção e acompanhamento. A emissão usa a alíquota cheia da competência fiscal e bloqueia quando o mês não está cadastrado. Iniciar/Retomar sincronização processa um lote imediatamente do cursor confirmado; os demais continuam pelo cron. A tela aberta também processa lotes adicionais por POST protegido. Com a tela fechada, a continuidade exige o cron nativo ativo. O progresso distingue processamento, pausa e fila sem atividade recente. A grid de e-mails possui pesquisa, paginação e salvamento individual, sem limite de dez destinatários.

### Tabelas IBPT anteriores e cadastro de chave

Na guia **Tabela IBPT**, a opção **Permitir instalar tabela IBPT de versão anterior (API ou CSV)** vem desabilitada por padrão. Habilite-a e salve as configurações para permitir uma substituição regressiva no botão **Atualizar tabela agora** (se a sua API retornar versão anterior) ou no envio de um CSV mais antigo. A seleção de tabela anterior suspende a renovação automática para não desfazer sua escolha na próxima emissão. Uma atualização manual posterior que aceite uma tabela mais recente retoma a renovação automática.

Se o CSV **não contiver a chave IBPT**, informe a **chave da tabela** no campo **Cadastrar chave IBPT junto com a tabela**. A chave informada é registrada com a versão, a UF, o período e o hash do arquivo. O painel mostra se veio do arquivo, da API ou se foi cadastrada manualmente. Não se gera chave fictícia e não se reaproveita chave de outra versão. A correção da chave informada é de responsabilidade do emitente.

## Recursos

- Menu **NFSE → Documentos** para notas emitidas, rascunhos e pendências, com ícone somente no item principal e sem submenu de configurações.
- Parâmetros em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional**, no formulário nativo da aplicação.
- Menu e guia NFS-e do cliente registrados pelas APIs PHP nativas do Perfex, com Font Awesome `fa-solid fa-file-invoice`; nenhum script do módulo reescreve menus ou ícones da aplicação.
- Views com painéis, colunas, formulários, abas e tabelas do Perfex; estilos adicionais limitados ao conteúdo da NFS-e, sem regras para a navegação ou os ícones globais.
- Endereço completo do estabelecimento na guia **Emitente**: CEP, logradouro, número, complemento, bairro, município, UF e IBGE. Reutiliza o endereço nativo da empresa quando não há endereço fiscal local; preserva os dados já revisados.
- Botão **Consultar** para CNPJ/CEP do emitente e dos tomadores. No emitente, atualiza os dados cadastrais retornados; nos tomadores, completa campos vazios. Alterações feitas durante a consulta são preservadas.
- Homologação identificada como **Produção restrita (homologação) — sem validade fiscal**. Bases oficiais fixas e distintas por ambiente; não há URLs ou provedores para o usuário configurar. Veja [ESTABELECIMENTO_AMBIENTES.md](docs/ESTABELECIMENTO_AMBIENTES.md).
- Botão **Emitir NFS-e** em cada linha elegível da grade **Vendas → Faturas**, com modal de ações e acesso ao histórico.
- Painel **NFS-e Nacional** na prévia da fatura, com emissão, rascunho, situação e histórico dos dois ambientes.
- Reaproveitamento do A1 da empresa, com campos permanentes para importar o A1 local e opção independente de prioridade.
- Guia **NFS-e** no cadastro de clientes.
- Tomador: próprio cliente, contato principal ou cadastro específico, inclusive dependente.
- Cadastro de vários tomadores por cliente, com CPF/CNPJ, contato, endereço e perfil próprio de serviço, ISS e IBS/CBS, ou herança dos padrões do cliente/empresa.
- Ação **Clonar** abre uma cópia editável dos dados e do perfil; somente o salvamento cria outro tomador, independente do original.
- Abas nativas **Dados para emissão**, **Tomadores** e **Faturas e NFS-e** dentro da guia fiscal do cliente.
- Campo **Tomador da NFS-e** na criação/edição da fatura, com pesquisa, seleção limitada ao cliente escolhido e aplicação automática em novos rascunhos.
- Catálogo nacional de serviços pesquisável por código ou descrição; os campos NBS oferecem busca semelhante, mas apenas com os NBS presentes na tabela IBPT instalada para a UF do emitente. Sem tabela, o NBS permanece digitável.
- Tributos aproximados calculados automaticamente pelo NBS e pela UF do emitente, com tabela IBPT obtida da fonte indicada, chave, versão e vigência preservadas por documento. Configuração por empresa, cliente e tomador. Veja [IBPT_ISSQN.md](docs/IBPT_ISSQN.md).
- Guia **Tabela IBPT** nas configurações: situação da tabela local, versão, chave, vigência e última atualização, com botão **Atualizar tabela agora** e importação manual de CSV. O cache vigente é reutilizado sem nova consulta. Se vencer, a cópia local continua calculando enquanto o módulo tenta renovar com intervalo mínimo de uma hora.
- Opção **Exibir aviso discreto de IBPT vencida na tela Documentos** (habilitada por padrão). Quando exibida, é apenas uma tarja pequena e informativa na listagem de documentos, sem modal, botão de fechar, confirmação ou bloqueio; pode ser desligada sem afetar o cálculo e a transmissão.
- Base, alíquota, valor e retenção do ISSQN visíveis no editor. A prévia calcula o imposto separadamente do IBPT; após a autorização, utiliza os valores do XML nacional.
- Rascunho editável e emissão em um clique quando os cadastros estão completos.
- Validação dos dados, geração da DPS, assinatura XML, validação XSD e transmissão.
- **Consultar transmissão** pela DPS original e recuperação pela chave de acesso conhecida, com conferência do emitente, ambiente e conteúdo fiscal antes de vincular o XML oficial à emissão. Uma falha de comunicação não gera nova numeração.
- Guias **No CRM**, **Emitidas no ambiente nacional**, **Recebidas** e **Pacote mensal** em Documentos. Sincronização incremental por NSU, XML cifrado, consulta e DANFSe das notas disponibilizadas ao certificado, inclusive externas ao CRM.
- Pedido de cancelamento, pedido de análise fiscal de cancelamento e confirmação/rejeição pelo tomador, com assinatura, consulta posterior, histórico e XML do evento registrado. A elegibilidade depende do emissor e das regras municipais/nacionais.
- Preparação de substituição das notas originadas no módulo, com nova DPS, vínculo à mesma fatura e preservação das versões. Não troca a identidade do tomador; a original só aparece cancelada após confirmação do evento fiscal.
- Pacote mensal por competência, emitidas/recebidas/ambas e XML/PDF/ambos, com ZIP e relação dos arquivos. Destinatários na guia **Destinatários de e-mail** das configurações, envio explícito pelo e-mail nativo e proteção contra duplicidade. Não bloqueia lançamentos do período.
- Proteção contra clique duplo e repetição de numeração.
- Reenvio explícito de uma DPS incerta usando o mesmo XML assinado, série e número, após consulta.
- Download de XML e DANFSe local; consulta pública oficial.
- Prévia do DANFSe antes da transmissão e após qualquer resultado, com carimbos de situação e sem criar uma emissão. Consulte [PREVIA_DANFSE.md](docs/PREVIA_DANFSE.md).
- Consulta dos eventos fiscais para identificar cancelamento confirmado, preservando o XML original e os registros da autorização.
- Gerador do DANFSe, QR Code e fontes originais Arial/Microsoft Sans Serif distribuídos prontos no módulo; não há consulta às fontes do Perfex ou download durante a geração.
- Envio de XML e PDF pelo e-mail configurado no Perfex, manual ou após autorização quando habilitado.
- Permissões separadas para visualizar, preparar, editar, transmitir, enviar, configurar, consultar logs, acessar/sincronizar o acervo nacional, cancelar, substituir, manifestar e enviar pacotes à contabilidade.
- **NFSE → Logs**: histórico por empresa, filtros, referência de cada tentativa, detalhes HTTP, download JSONL e relatório JSON de diagnóstico (operação completa ou período). Consulte [LOGS.md](docs/LOGS.md).
- Integração com o contexto e o serviço autenticado de certificados do módulo Multiempresa fornecido.
- Reaproveitamento dos dados da empresa, cliente, contato e fatura; identificação do município por cidade e UF sem consulta externa.
- Leitura opcional da referência de cobrança do módulo Asaas fornecido.

## Requisitos

- Perfex CRM 3.2.x e PHP 8.1 ou superior, **64 bits**.
- Extensões PHP OpenSSL, cURL, DOM, zlib, mbstring e JSON.
- Para gerar o DANFSe: **PHP 8.2 ou superior**, com ctype, cURL, filter, hash, JSON, mbstring, OpenSSL, PCRE, XML e zlib. Gerador e fontes originais validadas acompanham o módulo; não utiliza a instalação de TCPDF do Perfex.
- MySQL/MariaDB com tabelas InnoDB, transações e `GET_LOCK` / `RELEASE_LOCK` habilitados.
- `APP_ENC_KEY` válido e proteção CSRF nativa do Perfex habilitada.
- Saída HTTPS para os endereços oficiais descritos em [API.md](docs/API.md), com cadeia de confiança TLS atualizada.
- Certificado A1 `.pfx` ou `.p12` válido, chave RSA de pelo menos 2.048 bits e titular correspondente ao emitente.
- Emitente habilitado e município atendido pelo emissor público nacional para a operação pretendida.
- E-mail do Perfex configurado para enviar os documentos.

O pacote não precisa de Composer no servidor e não contém certificados ou senhas reais. As suítes locais geram identidades sintéticas somente durante a execução dos testes. As fontes licenciadas são preparadas no build do módulo, sem alterar o Perfex. Em PHP 8.1, a geração do DANFSe informa o requisito de atualização antes de carregar o motor; o diagnóstico não interrompe as demais telas do CRM.

## Instalação

1. Faça backup do banco e dos arquivos do Perfex.
2. Envie o ZIP em **Definições → Módulos**, ou extraia a pasta `nfse_nacional` em `modules/`.
3. Ative **NFS-e Nacional**. O instalador cria somente as tabelas do módulo, usando a conexão e o prefixo nativos da empresa atual, inclusive prefixo vazio.
4. Em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional**, confira os dados reaproveitados do emitente e preencha serviço, regime e tributação. A URL da seção é `admin/settings?group=nfse_nacional`.
5. Mantenha **Produção restrita — testes** até concluir a homologação. O módulo começa nesse ambiente e com produção desabilitada.
6. Escolha uma série de DPS própria para esta integração, entre 1 e 49.999. Se a série já foi usada, configure a numeração inicial correta; não reutilize números emitidos por outro aplicativo.
7. Configure as permissões dos colaboradores. A seção de parâmetros exige também acesso nativo às Configurações do Perfex.
8. Para usar o IBPT, na guia **Serviço e tributos**, selecione **Calcular automaticamente pelo IBPT (NBS)** e informe o NBS aplicável. A UF é obtida do emitente. O cálculo ocorre ao preparar ou salvar o rascunho e reutiliza a última tabela local consistente, inclusive se estiver vencida. A emissão não é impedida apenas por vencimento da tabela; a responsabilidade pelos percentuais é do emitente.

As atualizações `071` a `075` não alteram tabelas nem regravam notas ou parâmetros existentes. O IBPT usa o JSON do rascunho e um cache privado separado por empresa/UF. A escolha anterior da forma de informar tributos é mantida até sua alteração explícita. Na guia **Tabela IBPT**, a consulta manual pode obter o arquivo antes da primeira emissão, sem necessidade de selecionar um NBS. Também é possível selecionar o CSV original da UF do emitente e clicar em **Importar tabela**. O envio é independente da consulta externa e não salva outros campos das configurações; aceita CSV de até 8 MB, com dados estruturalmente consistentes, inclusive vencidos; não aceita tabelas futuras ou anteriores à cópia local. A leitura reconhece automaticamente o leiaute oficial e exportações com `Tipo=nbs`, `Tabela` e colunas `Aliquota*`, em ordem diferente. Com coluna `UF`, o nome do arquivo pode ser genérico. Se o CSV não trouxer a chave IBPT, informe a chave original no campo próprio; ela nunca é deduzida da versão ou reutilizada de outra tabela.

### Atualização de versões anteriores

Na instalação principal, substitua a pasta `modules/nfse_nacional` completa pelo conteúdo deste pacote, mantendo banco e chave de criptografia. Inclua também a pasta `vendor/`, que contém o motor e as fontes do DANFSe. Execute **Atualizar banco de dados** se o Perfex apresentar essa ação. A migração `044` cria preferências fiscais da fatura; `049` acrescenta a situação e evento cifrado aos documentos; `050` não altera o esquema. A migração `060` cria as tabelas do acervo nacional, eventos distribuídos, cursor NSU e histórico de pacotes. A migração `070` acrescenta revisões e vínculo de substituição aos documentos, atualiza a unicidade para fatura/ambiente/revisão e cria o histórico de pedidos fiscais. As alterações são restritas às tabelas do módulo. Configurações, certificado, sequências e XMLs existentes são preservados. As migrações são retomáveis; não desinstale para atualizar, pois a desinstalação elimina as tabelas fiscais.

Desde a versão 0.4.3, o pacote usa uma única data/hora UTC real de criação para seus arquivos, respeitando a precisão de dois segundos do ZIP. As versões anteriores repetiam a data fixa de 10/11/2026 às 00:00:00. Veja o diagnóstico e o procedimento em [ATUALIZACAO_043.md](docs/ATUALIZACAO_043.md). A versão em execução deve aparecer nas verificações da instalação, recolhidas no final das configurações: **0.8.0**.

**Em uma empresa provisionada pelo Multiempresa, a cópia privada também precisa receber a atualização aprovada pelo fluxo existente.** Essa etapa não se aplica à instalação principal, que executa seu próprio código. A versão 0.7.0 não altera o Multiempresa nem seu patch opcional.

Os novos fluxos, limites e cuidados de recuperação estão descritos em [OPERACOES_070.md](docs/OPERACOES_070.md). A sincronização contempla o histórico que o ADN disponibilizar ao certificado; não presume acervo irrestrito nem importa faturas artificiais. Substituição de notas importadas e operações de administração municipal não estão implementadas.

Os números intermediários de migração entre `020` e `070` estão presentes, sem alterações de esquema, para atender à sequência contínua exigida pelo atualizador nativo do Perfex. Isso permite atualizar a partir das versões anteriores sem a mensagem de lacuna na sequência.

### Consulta cadastral

Clique em **Consultar** ao lado do CNPJ ou CEP. Nos parâmetros do emitente, os dados retornados atualizam razão social, município IBGE, contato e inscrição municipal quando essa informação estiver presente. Isso inclui valores antes herdados do cadastro da empresa. Nos tomadores, a consulta completa campos vazios. Em ambos os casos, alterações feitas enquanto a consulta está em andamento são preservadas; se o documento consultado mudar, a resposta anterior é descartada. O cadastro só é gravado pela ação normal de salvar.

A inscrição municipal não é deduzida do CNPJ, CNAE ou inscrição estadual. Se não estiver no retorno, a tela orienta a conferência no cadastro municipal. Código de serviço, regime e tributação continuam sujeitos à configuração fiscal correta da empresa.

Não há seleção de API, token ou provedor nas telas. A integração reutiliza o serviço de consultas do Multiempresa quando disponível no contexto autenticado. O funcionamento e os requisitos de rede estão em [CONSULTAS_CADASTRAIS.md](docs/CONSULTAS_CADASTRAIS.md).

### Multiempresa

Provisione o módulo e ative-o na empresa correspondente pelo fluxo normal do Multiempresa. Cadastros, notas, sequências, configurações e certificado local pertencem ao banco/prefixo da empresa ativa. Não há seleção livre de empresa nas requisições.

Para reaproveitar o A1 já cadastrado, a integração usa o serviço autenticado `Certificates::credentials()` do Multiempresa, com conferência da sessão, funcionário, vínculo, organização e empresa. O módulo não lê diretamente o cofre ou a tabela central de certificados. A versão fornecida do Multiempresa restringe esse serviço ao administrador organizacional; essa autorização é preservada. Um colaborador sem esse acesso pode utilizar o certificado local da empresa quando sua prioridade tiver sido configurada por um usuário autorizado.

### Certificado A1

A tela apresenta a disponibilidade do certificado, seu titular e validade. Não há seleção de origem nem parâmetros de provedor.

O bloco **Certificado A1 local** mantém arquivo e senha sempre disponíveis. A opção **Usar certificado A1 local** controla a prioridade: quando habilitada e com certificado configurado, esse A1 tem preferência. Sem essa prioridade, o módulo procura o certificado disponibilizado para a empresa pela integração da aplicação e pelo Multiempresa; um local previamente cadastrado só é usado como alternativa se essas fontes estiverem ausentes. Uma negativa de autorização ou certificado inválido não é ignorada para tentar outra fonte silenciosamente.

O CPF/CNPJ do certificado é conferido com o emitente e, quando válido e disponível, com o cadastro da empresa ativa. A senha não é reapresentada na tela.

**Núcleo personalizado:** o núcleo da instalação não foi fornecido e não foi identificado um contrato nativo de A1 no material disponível. Foi criado o ponto de extensão interno `nfse_nacional_company_certificate`, com contexto validado, para integrar um serviço de certificados que exista no núcleo. Ele precisa ser conectado a esse serviço específico; não se declara descoberta automática de um armazenamento desconhecido. O usuário final não configura essa integração. Veja [CERTIFICADOS.md](docs/CERTIFICADOS.md).

### Configuração do tomador

Abra **Clientes → cliente → NFS-e**:

Na aba **Tomadores**, use **Novo tomador** para cadastrar quantos destinatários forem necessários. Cada cadastro pode ter seu próprio documento, endereço, e-mail, código de serviço e descrição da prestação. Os botões de edição/exclusão aparecem conforme a permissão do colaborador. Após salvar ou excluir, a aba Tomadores permanece selecionada.

Na aba **Dados para emissão**, defina o padrão do cliente:

- **Próprio cliente:** utiliza nome, CPF/CNPJ e endereço do cadastro; o e-mail vem do contato principal ativo. Cidade e UF reconhecidas preenchem o código IBGE. Número, bairro e complemento existentes são reaproveitados; complete somente os dados faltantes na guia fiscal.
- **Contato principal:** utiliza nome, telefone e e-mail do contato. Informe seu CPF/CNPJ na guia fiscal; não será presumido a partir do responsável financeiro.
- **Tomador cadastrado:** selecione ou cadastre o tomador/dependente com seus dados completos.

Uma fatura em nome do responsável pode gerar NFS-e para o dependente selecionado. A cobrança e o cadastro financeiro continuam vinculados à fatura original; o XML identifica o tomador fiscal configurado.

### Tomador específico da fatura

Ao criar ou editar uma fatura em **Vendas → Faturas**, o campo **Tomador da NFS-e** aparece abaixo do cliente. Escolha **Usar padrão do cliente**, **Próprio cliente**, **Contato principal** ou um tomador cadastrado. A lista possui pesquisa e é carregada somente para o cliente selecionado. Salvar a fatura grava essa preferência no módulo, sem mudar o responsável financeiro nem o padrão das outras faturas.

O contato principal só aparece quando há um contato ativo correspondente; seu CPF/CNPJ fiscal deve estar preenchido na aba Dados para emissão. Ao trocar o cliente da fatura, a lista é refeita e a escolha anterior é removida. Uma resposta atrasada de consulta não pode recolocar um tomador de outro cliente.

Antes da primeira emissão, o painel **Emitir NFS-e** abre com a preferência da fatura selecionada e permite escolher outro tomador para aquele documento. O rascunho recebe os dados, o código de serviço e a descrição do tomador escolhido. Na aba **Tomador** do rascunho, a seleção pode ser revista antes de salvar e transmitir.

Alterar a preferência da fatura não reescreve rascunhos existentes ou notas autorizadas. Quando já houver preparação, abra **Continuar rascunho** para revisar seu tomador. O módulo mantém uma NFS-e por fatura e ambiente; não distribui automaticamente o valor de uma cobrança entre vários tomadores. Veja [TOMADORES_FATURAS.md](docs/TOMADORES_FATURAS.md).

### Serviço e descrição padrão

Na guia **NFS-e** do cliente, selecione **Serviço padrão — código de tributação nacional** e preencha **Descrição padrão da prestação do serviço**. O seletor pesquisa códigos com ou sem pontuação e palavras da descrição oficial. A descrição da prestação é um texto próprio para a nota; selecionar um código não sobrescreve esse texto.

O modal **Novo tomador / Editar tomador** oferece os mesmos dois campos. Eles são salvos especificamente para esse tomador e têm prioridade quando ele é escolhido para emissão. Campos vazios herdam, individualmente, o padrão do cliente e depois o da empresa. Sem qualquer descrição configurada, o rascunho utiliza os itens da fatura.

O catálogo inclui os códigos nacionais da lista aplicável e os novos códigos disponíveis somente em produção restrita, identificados na seleção. Não se confunde com CNAE, NBS ou código municipal. Consulte [CATALOGO_SERVICOS.md](docs/CATALOGO_SERVICOS.md) para versão, quantidade, fontes, comparação realizada e limites da conferência.

O código é validado no servidor ao salvar e novamente antes da transmissão, considerando o ambiente da nota. O cadastro não determina automaticamente alíquota, retenção ou enquadramento fiscal. Atualizar os padrões afeta novas preparações; rascunhos existentes preservam a revisão já feita e notas autorizadas preservam seus dados originais.

## Uso

Em **Vendas → Faturas**, use **Emitir NFS-e** abaixo do número da fatura, diretamente na grade. A ação permanece visível sem passar o mouse e funciona após pesquisar, ordenar ou paginar a listagem. Ela abre as ações e o histórico da fatura escolhida em um modal:

- **Rascunho:** prepara ou abre o documento para revisar competência, serviço, tomador e valores.
- **Emitir NFS-e:** prepara, valida, assina e transmite em uma ação. Quando houver pendências, a tela informa os campos e oferece acesso ao rascunho.

Usuários com acesso somente de consulta e faturas canceladas ou ainda em rascunho recebem **Consultar NFS-e**, sem ação de transmissão. O acesso à fatura, ao cliente e à empresa é conferido novamente no servidor.

O mesmo painel continua disponível na prévia da fatura e as ações permanecem na guia NFS-e do cliente. Se já existe preparação, o painel oferece **Continuar rascunho**; se autorizada, permite abrir a nota. O status é atualizado no painel após a operação. O menu de ações da fatura possui também o atalho **NFS-e Nacional**, que abre o mesmo painel em modal quando o tema não renderiza o ponto de extensão final da prévia.

O menu do módulo separa **Todas**, **Notas emitidas** e **Rascunhos e pendências**, preservando filtros por cliente, situação e ambiente. O resultado é limitado aos 200 registros mais recentes do filtro, com essa condição indicada na tela.

O rascunho fica no Perfex. O `POST /nfse` é uma solicitação efetiva de emissão, não um salvamento de rascunho no portal.

A base sugerida usa o subtotal dos serviços e o desconto da fatura. Se o total financeiro incluir tributos, acréscimos ou outros ajustes, é exigida uma conferência explícita antes da transmissão. A competência também pode ser revisada. Alterações posteriores na fatura invalidam a revisão anterior.

Após autorização, o documento fica bloqueado para edição. O tomador, os valores e o XML da emissão são preservados; mudar o cadastro do cliente não altera notas autorizadas.

Se a fatura for transferida para outro cliente depois de criar seu histórico fiscal, o módulo bloqueia nova preparação e sinaliza a necessidade de revisar o vínculo. Dados do cliente anterior não são expostos ao novo cliente.

### Falha ou demora na comunicação

O estado **Aguardando consulta** significa que a resposta não confirmou o resultado. Use **Consultar resultado**. Se a nota ainda não for localizada, **Reenviar a mesma DPS** faz nova consulta e, somente se ausente, repete o mesmo documento assinado. Nenhuma nova numeração é criada para resolver um timeout.

Uma resposta com a mesma identificação, mas conteúdo fiscal diferente, não pode ser vinculada à fatura. Confira colisões de série/numeração com outros emissores.

### Envio ao destinatário

O envio usa o e-mail congelado no documento e exige a permissão **Enviar**. A opção de envio após autorização precisa ser marcada explicitamente. A aceitação pelo servidor SMTP não comprova leitura nem entrega na caixa final.

Se o resultado SMTP ficar incerto, confira os registros do servidor antes de usar **Reenviar após conferência**. Essa ação é explícita, mantém documentos e destinatário e pode gerar uma segunda cópia se a primeira já tiver sido entregue.

### DANFSe

O PDF é gerado do XML autorizado. A API antiga do DANFSe foi suspensa em 03/08/2026; o módulo não depende dela. O renderer segue a disposição de campos, A4 em uma página, logomarca, identificação de homologação e QR Code da NT 008 v1.02.

**Recursos internos:** o módulo inclui seu motor de PDF, suas dependências e o gerador de QR Code. Não procura bibliotecas ou fontes no núcleo do Perfex, não executa Composer e não baixa recursos durante a geração. As classes e constantes do motor possuem isolamento próprio para coexistir com as bibliotecas do CRM. Após incluir o pacote de fontes originais, o download e o envio geram o PDF a partir do XML autorizado, inclusive para notas já arquivadas.

**Tipografia:** a seção 2.4 da [NT 008 v1.02](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-008-se-cgnfse-danfse-20260714-v1-02.pdf) especifica Arial para títulos/rótulos e Microsoft Sans Serif para conteúdo. A versão 0.4.5 exige essas famílias e não utiliza substitutas. Os originais licenciados `arial.ttf`, `arialbd.ttf` e `micross.ttf` ainda precisam ser fornecidos para preparação e inclusão no próprio módulo. A [FAQ da Microsoft](https://learn.microsoft.com/en-us/typography/fonts/font-faq) distingue os direitos de incorporação em documentos da redistribuição em aplicativos. Enquanto esses recursos estiverem ausentes, o diagnóstico e a nota autorizada informam a pendência do DANFSe; XML e consulta oficial continuam disponíveis. O motor não gera PDF com Liberation Sans ou fontes do Perfex.

Detalhes de isolamento, requisitos e manutenção: [DANFSE_EMBUTIDO.md](docs/DANFSE_EMBUTIDO.md).

## Limites desta versão

- Serviços nacionais regulares, emitidos pelo prestador, para tomador brasileiro identificado.
- ISSQN: operação tributável ou não incidência; retenção conforme seleção explícita.
- IBS/CBS: grupo básico opcional, classificação e indicador de operação informados pelo usuário, destinatário igual ao tomador e finalidade regular. O módulo não escolhe enquadramento tributário nem estima alíquotas da reforma.
- Não implementa exportação, imunidade, deduções especiais, retenções federais detalhadas, intermediário, obra, evento, imóvel, notas de crédito/débito ou grupos avançados de IBS/CBS.
- Esta versão não solicita cancelamento, substituição ou manifestação fiscal. A ação **Atualizar situação** consulta eventos já registrados para reconhecer cancelamentos. Não existe sincronização automática por fila; o PDF usa a última situação confirmada e informa a data da consulta quando disponível.
- Uma emissão por fatura e ambiente. Não divide uma fatura entre vários tomadores e não emite automaticamente por pagamento/webhook.
- Autenticação direta por A1. Login/senha ou conta gov.br do emissor web não substituem o certificado da API. Procurações e emissão com certificado de terceiro não estão implementadas.
- Os XSD incluídos vieram de espelho público dos artefatos nacionais; hashes e procedência constam em `schemas/provenance.json`. O ZIP oficial não pôde ser baixado neste ambiente (HTTP 403), portanto não houve comparação binária com a distribuição oficial.
- O pacote de produção publicado em fevereiro de 2026 ainda restringe CNPJ a números; a distribuição de testes de outubro aceita CNPJ alfanumérico. O cadastro preserva letras, e a validação sinaliza a limitação do esquema selecionado.

## Segurança, backup e desinstalação

Certificado, senha, XML enviado e XML recebido são cifrados com AES-256-GCM, vinculados à chave da instalação e ao contexto da empresa. Os dados cadastrais do módulo usam o mesmo banco protegido do Perfex. Não há arquivos fiscais públicos nem endpoint anônimo de transmissão.

Preserve **banco, `APP_ENC_KEY`, identidade da empresa e prefixo** no backup. Perder a chave impede abrir os conteúdos cifrados. Trocar o host do banco, mantendo os elementos anteriores, não altera a chave de leitura.

A exclusão de uma fatura com vínculo fiscal é bloqueada pelo hook nativo. Rascunhos ainda sem DPS podem ser descartados no módulo. Administradores mantêm acesso de recuperação ao arquivo fiscal mesmo que registros de origem sejam removidos por importação ou intervenção externa.

Desativar o módulo preserva seus dados. **Desinstalar remove as sete tabelas do módulo na empresa atual, incluindo XMLs, certificado e preferências por fatura.** Exporte e faça backup antes de confirmar a desinstalação nativa.

## Desenvolvimento

Código em `controllers/`, `models/` e `libraries/NfseNacional/`; telas em `views/` e `assets/`.

```bash
PHP_BIN=php sh tools/test.sh
# A geração real do PDF usa os recursos do módulo e é obrigatória na suíte.
PHP_BIN=php PDF_TEST_OUTPUT=/tmp/nfse-teste sh tools/test.sh
python3 tools/build.py /caminho/nfse-nacional-module-0.4.6.zip
```

Consulte [API.md](docs/API.md), [CERTIFICADOS.md](docs/CERTIFICADOS.md), [TESTES.md](docs/TESTES.md), [HOMOLOGACAO.md](docs/HOMOLOGACAO.md), [CHANGELOG.md](CHANGELOG.md) e [THIRD_PARTY.md](THIRD_PARTY.md).


### Busca de NBS (0.8.0)
Ao instalar/importar a tabela IBPT da UF do emitente, os campos NBS oferecem busca por código e descrição, sem chamada adicional à API. Sem tabela, informe o NBS manualmente. Tabelas vencidas continuam consultáveis.
