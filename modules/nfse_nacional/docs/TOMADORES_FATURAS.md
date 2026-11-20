# Tomadores por cliente e por fatura

## Cadastro no cliente

Em **Clientes → cliente → NFS-e**, a aba **Tomadores** reúne os destinatários fiscais desse cliente. Use **Novo tomador** para cadastrar dependentes, unidades ou outras pessoas que recebam os serviços. Não é necessário duplicar o cliente responsável pela cobrança.

Cada tomador possui identificação interna, nome, CPF/CNPJ, endereço, e-mail, telefone e perfil próprio de serviço, ISS e IBS/CBS. Cada parâmetro pode herdar o padrão do cliente/empresa. As consultas cadastrais e o catálogo de serviços já existentes no módulo permanecem disponíveis no modal. O cadastro exige permissão de edição fiscal. A ação **Clonar** abre uma cópia editável, gravada somente após salvar. Veja [PERFIS_EMISSAO.md](PERFIS_EMISSAO.md).

A aba **Dados para emissão** define o padrão geral do cliente: próprio cliente, contato principal ou tomador cadastrado. A aba **Faturas e NFS-e** reúne as ações por cobrança e o histórico fiscal. Os formulários e abas utilizam os componentes nativos do CRM.

## Escolha na fatura

No formulário de criação/edição em **Vendas → Faturas**, selecione o cliente e use **Tomador da NFS-e**, imediatamente abaixo. As opções são:

| Opção | Comportamento em um novo rascunho |
|---|---|
| Usar padrão do cliente | Resolve o padrão fiscal vigente do cliente na preparação |
| Próprio cliente | Utiliza o cadastro do responsável financeiro como tomador |
| Contato principal | Utiliza o contato principal ativo e os complementos fiscais do perfil |
| Tomador cadastrado | Utiliza o cadastro específico pertencente àquele cliente |

A escolha é salva junto ao ciclo de gravação da fatura, em tabela própria do módulo. O pagador, o cliente da cobrança, seus contatos e os demais dados financeiros não são substituídos. Duas faturas do mesmo cliente podem indicar tomadores diferentes.

O campo tem pesquisa e acompanha as alterações do cliente. Trocar o cliente remove a seleção incompatível e carrega a lista correspondente; o sistema descarta respostas de consultas anteriores. Requisições são autorizadas novamente no servidor, incluindo o contexto da empresa.

## Escolha na emissão

O painel **Emitir NFS-e**, acessível pela grade e prévia da fatura, apresenta a preferência gravada antes da primeira preparação. O operador pode escolher outro tomador para esse novo documento e clicar em **Rascunho** ou **Emitir NFS-e**.

O rascunho recebe os dados do tomador e seu perfil completo de emissão. Campos em **Usar padrão** herdam o cliente e depois a empresa; zeros e campos opcionais explicitamente vazios são preservados. Na aba **Tomador** do rascunho é possível selecionar o próprio cliente, o contato principal ou outro cadastro do mesmo cliente, revisar os campos e salvar antes de transmitir.

O código/descrição fiscal não altera os itens financeiros da fatura. O sistema continua permitindo revisar valores e competência do documento antes da autorização.

## Preservação do histórico

Uma preferência da fatura orienta somente novas preparações. Quando já existe rascunho, a alteração deve ser feita dentro dele; mudar a preferência da fatura ou o cadastro do tomador não reescreve esse documento. Após autorização, a nota conserva seus dados e o XML original.

Cliques repetidos para a mesma preparação reaproveitam o documento existente. Uma escolha diferente enviada para uma fatura com documento preparado é recusada com orientação para abrir o rascunho. A regra atual permanece **uma NFS-e por fatura e ambiente**, com um tomador em cada documento. Não há divisão automática de valores entre vários destinatários.

Um tomador utilizado como padrão do cliente ou predefinido em uma fatura não pode ser excluído até substituir essa referência. Notas já arquivadas possuem dados próprios e não dependem da permanência do cadastro para serem consultadas.

## Instalação e integração

A migração `044` cria a tabela `{prefixo}nfse_nacional_invoice_preferences`, com chave da fatura, cliente, seleção e identificação/data da atualização. A preferência não é gravada em colunas adicionais das tabelas nativas do Perfex. Os cadastros existentes de tomadores são reutilizados.

O módulo utiliza os hooks nativos de gravação da fatura para retirar seus campos do payload antes do SQL, validar o vínculo e persistir a escolha após a operação. A atualização de uma fatura sem os campos fiscais preserva a preferência, exceto quando o cliente efetivo foi alterado. O formulário exibe o campo a partir de um partial do módulo, restrito ao formulário nativo observado `form#invoice-form`, após `.f_client_id`; não altera a navegação nem outros formulários.

A integração de referência para Perfex 3.2+ utiliza `before_update_invoice` e `invoice_updated`. Nesse contrato, a alteração exclusiva do tomador também sinaliza sucesso pelo resultado `updated` passado por referência, sem modificar artificialmente campos financeiros. Existe adaptação de payload para os hooks legados, mas não foi confirmado que o núcleo antigo dispare `after_invoice_updated` quando nenhuma coluna da fatura muda. Por isso, a edição exclusiva do tomador não é declarada homologada nessa família legada.

Os testes locais usam o model e os hooks reais do módulo, banco simulado e dados fictícios. O formulário nativo foi observado no portal sem gravar faturas. A instalação desta versão e a verificação de persistência no banco real do CRM não foram realizadas durante a construção do pacote.
