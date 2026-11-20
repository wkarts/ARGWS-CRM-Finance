# Perfil de emissão por tomador e clonagem

## Configuração

Em **Clientes → cliente → NFS-e → Tomadores**, abra **Novo tomador** ou **Editar**. A aba **Dados do tomador** contém identificação e endereço. A aba **Perfil de emissão** organiza os parâmetros em **Serviço**, **ISS e tributos** e **IBS e CBS**.

Cada campo editável tem **Usar padrão**. Marcado, o campo acompanha o padrão do cliente ou da empresa. Desmarcado, o valor pertence ao tomador. O formulário mostra o valor herdado para facilitar a conferência.

| Grupo | Parâmetros disponíveis |
|---|---|
| Serviço | Código nacional pesquisável, código municipal, NBS, município da prestação e descrição |
| ISS e tributos | Tributação, retenção, alíquota de ISS, forma de informar tributos aproximados, valores federal/estadual/municipal e percentual aproximado do Simples |
| IBS e CBS | Informar ou não o grupo, indicador da operação, CST, cClassTrib e consumidor final |

O perfil padrão do cliente também pode personalizar esses campos em **Dados para emissão**. A precedência é **tomador → cliente → empresa**. Ela é resolvida individualmente, sem confundir valor zero com ausência.

Nesta versão, a finalidade continua **emissão normal** e o destinatário de IBS/CBS é **o próprio tomador**. Os perfis reutilizam os grupos já suportados pelo emissor. Não acrescentam cálculo de alíquotas/valores de IBS/CBS, correlação automática entre código de serviço e cClassTrib, nem novos grupos de PIS/COFINS, redução, diferimento ou crédito presumido. A seleção continua sujeita às validações fiscais do documento e do ambiente nacional.

## Herança, zero e campos vazios

- **Usar padrão marcado:** o campo não é gravado como valor próprio; mudanças no padrão passam a valer nas próximas preparações.
- **Valor próprio zero:** continua zero. Por exemplo, alíquota `0,00` não volta automaticamente à alíquota do cliente.
- **Valor próprio vazio em campo opcional:** omite aquela informação, mesmo quando o cliente possui um valor. Aplica-se, por exemplo, a código municipal, NBS e alíquota opcional.
- **Município da prestação próprio vazio:** utiliza o município do emitente.
- **Descrição própria vazia:** utiliza a descrição dos itens da fatura.
- **Serviço nacional próprio:** deve ser selecionado no catálogo. Para herdar o serviço, marque **Usar padrão**.

O grupo IBS/CBS pode ser explicitamente desabilitado para um tomador, mesmo quando habilitado no padrão. Código CST, cClassTrib e indicador de operação incompletos podem permanecer em um cadastro em preparação, mas impedem a transmissão quando exigidos pelo enquadramento selecionado.

Os limites de formato são conferidos no servidor, incluindo códigos, campos permitidos, valores não negativos e precisão decimal. Essa validação técnica não determina o enquadramento tributário da operação.

## Clonar um tomador

Clique em **Clonar** na linha do cadastro. O modal abre uma cópia editável, na aba dos dados cadastrais. Confira identificação, CPF/CNPJ e os demais campos; depois clique em **Salvar**.

O clique em Clonar não grava nada. **Cancelar** ou **Esc** fecha a cópia sem criar registro. O salvamento utiliza o endpoint de criação do cliente, sem o ID do tomador original.

A cópia recebe os dados cadastrais, os valores próprios do perfil e a escolha de herdar em cada campo. Depois de salva, alterar um tomador não reescreve o outro. Os campos que continuam em **Usar padrão** acompanham normalmente as configurações comuns do cliente/empresa. Faturas, preferências de tomador, documentos fiscais e histórico não são clonados.

## Aplicação na fatura e no documento

O campo **Tomador da NFS-e** da fatura continua definindo a seleção inicial. A primeira preparação resolve o perfil completo e grava seus valores no rascunho. Valores dos serviços, descontos, competência e responsável pela cobrança permanecem informações da fatura/documento.

Trocar explicitamente o tomador no rascunho preenche os dados cadastrais e todos os parâmetros fiscais do novo perfil. Isso inclui limpar valores opcionais ou desabilitar IBS/CBS quando o novo tomador assim estiver configurado. A revisão manual feita em seguida pertence àquela nota.

Salvar novamente com o mesmo tomador não restaura os padrões sobre um rascunho já revisado. Alterar o cadastro ou os padrões não modifica rascunhos existentes nem XMLs autorizados.

## Persistência e compatibilidade

Os mapas são guardados em `emission_profile`, dentro do JSON já existente do cliente e do tomador. Não há tabela de tomadores duplicada nem coluna fiscal acrescentada às faturas do CRM.

O mapa é esparso: chaves ausentes herdam, string vazia representa a opção explícita de campo vazio e strings `0`/`0.00` são valores próprios. A presença de `emission_profile`, mesmo vazio, torna esse mapa a representação canônica.

Cadastros antigos sem essa propriedade têm seus campos legados de serviço traduzidos na leitura, sem alteração em massa do banco. No próximo salvamento, o mapa canônico é gravado e os equivalentes legados são removidos. Formulários antigos que enviam apenas código/descrição continuam aceitos e preservam os demais grupos fiscais.

As requisições e a listagem permanecem limitadas ao cliente e à empresa autorizados. Apenas usuários com permissão de edição recebem as ações de criar, editar, clonar e excluir.
