# Tributos aproximados pelo IBPT e ISSQN — 0.7.6

## Configuração e uso

Em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional → Serviço e tributos**, escolha **Calcular automaticamente pelo IBPT (NBS)** no campo **Tributos aproximados**. Informe o NBS de nove dígitos do serviço e confira a UF do estabelecimento emitente. Quando a UF estiver ausente em um cadastro legado, o módulo a deriva dos dois primeiros dígitos do código IBGE do emitente. Divergências entre UF e IBGE precisam ser corrigidas.

O perfil fiscal do cliente ou de cada tomador pode herdar essa opção ou definir outra forma de informar os tributos. A seleção do tomador na fatura e no rascunho continua aplicando seu próprio serviço, NBS e tributação. A UF da tabela é sempre a do emitente; não é substituída pela UF do tomador ou pelo município da prestação.

Ao preparar a fatura ou salvar um rascunho no modo IBPT, o servidor consulta a tabela da UF e procura o NBS exato. Valores, desconto incondicional e NBS revisados produzem uma nova estimativa. A fonte, chave, versão, vigência e hash da tabela ficam associados ao documento. A descrição do serviço permanece íntegra.

A interface mostra as três esferas tributárias, seus percentuais, valores em reais, total, fonte/chave, vigência, versão e o texto complementar da nota. Alterações ainda não salvas ocultam os valores anteriores e orientam a salvar. A atualização após salvar não exige recarregar a página.

### Fonte utilizada

`https://ibpt.wwsoftwares.com.br/tabela/ibpt/{UF}`

O endereço HTTP fornecido redireciona para HTTPS. O módulo usa diretamente HTTPS com verificação de certificado do servidor. Nenhum CPF/CNPJ, dado de cliente, certificado A1 ou senha é transmitido à fonte da tabela: a requisição contém somente a UF. Não há seletor de fornecedor ou URL editável na interface.

O arquivo retornado é CSV com `;`. O parser aceita UTF-8 válido e converte Windows-1252 quando necessário, inclusive quando o servidor anuncia UTF-8 incorretamente. Campos utilizados: `codigo`, `tipo`, `descricao`, `nacionalfederal`, `estadual`, `municipal`, `vigenciainicio`, `vigenciafim`, `chave`, `versao` e `fonte`. Somente registros `tipo=1`, com NBS de nove dígitos, participam da consulta. Não há aproximação por descrição nem fallback para NCM, LC 116 ou código nacional de tributação.

## Cálculo

```
base = valor dos serviços − desconto incondicional
federal = arredondar(base × nacionalfederal ÷ 100, 2)
estadual = arredondar(base × estadual ÷ 100, 2)
municipal = arredondar(base × municipal ÷ 100, 2)
```

O cálculo utiliza inteiros/decimal fixo, preserva até quatro casas nas alíquotas IBPT e arredonda cada esfera para centavos, com meio centavo para cima. Desconto condicional não diminui essa base. Valores estaduais iguais a zero são preservados; o módulo não presume que todos os serviços tenham percentual estadual zero.

Exemplo exclusivamente demonstrativo: base R$ 252,00, federal 13,45%, estadual 0% e municipal 5% resultam em R$ 33,89 federal, R$ 0,00 estadual e R$ 12,60 municipal. Para ME/EPP optante pelo Simples, com percentual informado de 3%, o texto segue o formato:

> Optante do Simples Nacional. Trib. aprox. R$ 33,89 Federal, R$ 0,00 Estadual e R$ 12,60 Municipal. Fonte: IBPT [CHAVE_DA_TABELA]. Alíquota do Simples Nacional: 3,0000%.

A chave vem do registro consultado e nunca é fixada no código. O percentual do Simples não é obtido da tabela IBPT, não é inferido de CNAE/CNPJ e não é somado novamente à carga aproximada. Ele deve ser informado no perfil aplicável. O enquadramento e o texto do Simples só são incluídos quando correspondem ao emitente.

### Vigência e disponibilidade

A situação da vigência é aferida pela data de emissão, não pela competência da fatura. **Vencimento é condição informativa e não impede o cálculo, a validação local nem a tentativa de transmissão com percentuais IBPT armazenados.** A UF, o NBS, a chave, a versão, a vigência e o hash continuam registrados com a estimativa. Tabelas futuras ou estruturalmente inválidas não são aceitas. Documentos já emitidos conservam os dados usados originalmente; consultas, recuperação por DPS/chave e reenvio do mesmo XML não recalculam nem modificam o conteúdo assinado.

O cache é privado e separado por empresa/UF. A tabela local vigente é reutilizada até o vencimento, sem download diário ou a cada emissão. A renovação automática ocorre ao preparar ou salvar um rascunho com IBPT quando a tabela estiver ausente ou vencida. Não é um agendamento independente do uso: sem emissão ou atualização manual, o módulo não consulta a fonte em segundo plano. Após falha ou retorno sem tabela atualizada, uma nova tentativa automática ocorre no próximo uso após uma hora. Durante esse intervalo, a emissão utiliza a cópia anterior, mesmo vencida, se estruturalmente válida para UF/NBS.

Na guia **Tabela IBPT**, dentro das configurações da NFS-e, são exibidos UF do emitente, situação, versão, chave, fonte, início/fim da vigência, última obtenção, última tentativa e quantidade de serviços. Abrir a guia consulta somente o armazenamento local. **Atualizar tabela agora** antecipa a obtenção, mesmo com uma tabela ainda vigente, respeitando um minuto entre tentativas. Não exige NBS nem certificado A1 e não salva outros campos do formulário. A UF vem dos dados salvos do emitente; se UF/IBGE forem editados, salve os parâmetros antes de atualizar.

Falha de rede ou fonte regressiva/inválida não substitui a última tabela local consistente. **Tabela vencida continua disponível para calcular e emitir**; o vencimento isoladamente não cria pendência fiscal nem exige confirmação. NBS inexistente, falta de tabela, chave ausente, tabela futura ou resposta inválida não são convertidos em percentual zero: sem um snapshot estruturalmente válido, o rascunho é salvo com pendência e pode exigir revisão. A aplicação nunca troca silenciosamente o modo de cálculo. O usuário é responsável por manter os percentuais de tributos aproximados atualizados; as validações fiscais independentes do IBPT permanecem aplicáveis.

Em **11/11/2026**, a fonte indicada retornou para BA a versão **26.2.B**, chave **C44399**, com vigência de **20/09/2026 a 31/10/2026**, portanto vencida para novas emissões nessa data. Esse arquivo não é distribuído como tabela vigente no módulo. A página oficial IBPT anunciava a versão 26.2.C, vigente de 20/10/2026 a 30/11/2026. A fonte ou um CSV atualizado é recomendado, mas **não é pré-requisito para emitir com a última tabela armazenada**, ainda que vencida.

### Aviso opcional, não bloqueante, em Documentos

Em **Definições → Configurações → Documentos Fiscais → NFS-e Nacional → Tabela IBPT**, o parâmetro **Exibir aviso discreto de IBPT vencida na tela Documentos** vem **habilitado por padrão**. A preferência é salva por empresa, nos mesmos parâmetros de NFS-e. Desativá-la **oculta somente o aviso** e não afeta cálculos, validações, assinatura ou transmissão.

Quando a tabela da UF da empresa estiver vencida, a listagem **NFSE → Documentos** pode apresentar uma **tarja pequena**, informando “IBPT vencida”, UF, versão e fim da vigência. Não há modal, janela, botão de fechar, foco forçado, necessidade de confirmar ou interrupção do botão de emitir. A obtenção desse aviso usa somente o cache local, sem consulta HTTP. Se o parâmetro estiver desabilitado, ou a tabela estiver vigente, não há tarja.

O aviso serve para orientar a manutenção da tabela e **não constitui autorização fiscal**. A SEFIN continua responsável por aplicar suas próprias regras de recepção e processamento.

### Permitir substituição por versão anterior

O parâmetro **Permitir instalar tabela IBPT de versão anterior (API ou CSV)** é configurado por empresa, na guia **Tabela IBPT**, e vem **desabilitado por padrão**. Para instalar uma versão anterior à atual: habilite o parâmetro, **salve as configurações** e execute uma nova atualização por API ou importacão por CSV. O servidor utiliza exclusivamente a preferência salva no contexto da empresa; o navegador não consegue forçar a opção num POST isolado.

A consulta pela API continua no endereço fixo da WWSoftwares e instala a versão que aquela fonte efetivamente devolver: o parâmetro **não** transforma a API em catálogo de versões históricas. Para selecionar uma edição específica, use o CSV correspondente. Tabelas incompatíveis, futuras ou com dados fiscais inconsistentes continuam recusadas. Uma tabela vencida válida permanece utilizável.

A substituição intencional por versão anterior preserva a tabela escolhida na emissão seguinte, mesmo se estiver vencida: o cache fica marcado como **renovação automática suspensa**. Para voltar à renovação normal, obtenha uma tabela mais recente por atualização manual. Documentos já emitidos não são regravados.

## Envio manual do arquivo IBPT

Na mesma guia **Tabela IBPT**, use **Enviar tabela manualmente**:

1. Selecione o CSV original da UF do emitente, de até **8 MB**.
2. Se o arquivo não contiver a chave, use o campo **Cadastrar chave IBPT junto com a tabela (se ausente no CSV)** para identificá-la. Ela será persistida junto da versão, UF e vigência; o painel identifica a origem como **Cadastrada manualmente no envio**.
3. Clique em **Importar tabela**.
4. Confira situação, versão, chave e vigência no painel. **Obtenção da tabela** passa a indicar **Arquivo enviado**.

O envio não consulta a fonte externa, não exige NBS ou A1 e não depende do intervalo de atualização remota. Apenas o arquivo, a UF esperada e a chave opcional preenchida são enviados, além do token CSRF nativo; outros campos, senhas e certificados do formulário não acompanham a importação. A UF efetiva é resolvida no servidor pelo cadastro salvo da empresa.

O leitor manual identifica as colunas pelo nome, independentemente da ordem. Reconhece o leiaute numérico original e exportações com `Codigo`, `Excecao`, `Tipo`, `UF`, `Tabela`, `Descricao`, `AliquotaNacionalFederal`, `AliquotaImportadosFederal`, `AliquotaEstadual`, `AliquotaMunicipal`, `VigenciaInicio` e `VigenciaFim`. `Tabela` corresponde à versão; `Tipo=ncm/nbs/lc116` corresponde às classificações numéricas `0/1/2`. Somente NBS é retido para a emissão de serviços.

Quando existe coluna `UF`, o conteúdo é conferido contra o emitente e o nome pode ser genérico, como `ibpt-consulta (1).csv`. No leiaute original sem UF, o módulo a confere pelo nome do arquivo, reconhecendo `TabelaIBPTaxBA26.2.C.csv`, `IBPT_BA_26.2.C.csv` e `BA.csv`. A conferência do conteúdo ou do nome não constitui assinatura de autenticidade: use o arquivo correspondente ao estado, sem editar seus percentuais.

Se a exportação omitir `Chave`, o campo opcional complementa esse metadado. É necessário informar a chave original daquela tabela; a versão não permite calculá-la e o módulo não reutiliza a chave da cópia anterior. Se o arquivo já contém uma chave, um complemento divergente é recusado. Quando a coluna `Fonte` estiver ausente, usa-se o rótulo declarado **IBPT**, sem atribuir um domínio de origem não informado. O hash preservado corresponde ao arquivo original recebido, antes da normalização das colunas.

São validados formato, codificação, colunas, NBS, limites, alíquotas, chave, versão e datas. Arquivo sem UF identificável no conteúdo ou no nome, sem chave original, divergente do emitente, futuro, inconsistente ou anterior à tabela local é recusado. A validade encerrada é informada e não impede a importação estruturalmente válida. A gravação só ocorre após todas as validações, com substituição atômica. A rejeição mantém a cópia anterior e seus metadados intactos.

Após a importação, a tabela fica no mesmo armazenamento privado por empresa/UF e é reutilizada inclusive depois do vencimento, enquanto não houver substituta mais atualizada. A consulta externa posterior não substitui uma tabela local vigente por uma versão regressiva ou sem vigência atual. A operação **Importação do arquivo IBPT** aparece nos logs sem conteúdo bruto do CSV. Notas transmitidas e estimativas fiscais já assinadas permanecem inalteradas.

## XML e DANFSe

No modo IBPT, os três valores seguem em:

```
DPS/infDPS/valores/trib/totTrib/vTotTrib/vTotTribFed
DPS/infDPS/valores/trib/totTrib/vTotTrib/vTotTribEst
DPS/infDPS/valores/trib/totTrib/vTotTrib/vTotTribMun
```

O texto com a chave da fonte segue em `DPS/infDPS/serv/infoCompl/xInfComp`. Como `totTrib` é uma escolha exclusiva, não se acrescenta também `pTotTribSN` no modo IBPT. O percentual do Simples, quando informado, permanece no texto complementar. Os dois esquemas XSD distribuídos são verificados nos testes.

A prévia usa os dados locais validados, com carimbo e sem validade fiscal. Quando o IBPT estiver pendente, o PDF de conferência continua disponível com aviso e sem carga aproximada fictícia. Após a autorização, o DANFSe é gerado a partir do XML oficial recebido, inclusive seus valores e informações complementares. Arial, Arial Bold e Microsoft Sans Serif continuam incorporadas no módulo.

## ISSQN

A alíquota do ISSQN é um campo próprio nos parâmetros da empresa, no perfil fiscal do tomador e na guia **Tributação** da emissão. Em **Serviço e valores**, o resumo destaca **base de cálculo, alíquota, valor do ISSQN e retenção**. Antes da autorização, o título identifica o cálculo como prévia:

```
base local ISSQN = valor dos serviços − desconto incondicional
ISSQN local = arredondar(base local × alíquota informada ÷ 100, 2)
```

Exemplo: R$ 252,00 × 3% = R$ 7,56 de ISSQN calculado, embora a carga municipal IBPT do mesmo exemplo seja R$ 12,60. São informações distintas. Se a alíquota não estiver informada ou a operação não for tributável, a prévia não inventa um valor apurado.

O valor oficial é obtido de `NFSe/infNFSe/valores/vBC`, `pAliqAplic` e `vISSQN`. Não é fabricado um grupo `vISSQN` na DPS. Regras de município, regime, retenção, benefícios e deduções podem tornar a apuração autorizada diferente da simulação.

`pAliq` não é enviado nos casos conhecidos em que é vedado independentemente da consulta de convênio: MEI, não incidência, regime especial e ME/EPP com ISS no Simples sem retenção. A validação impede alíquota de ISSQN superior a 5%. Nos demais cenários, as regras e a parametrização municipal continuam sujeitas à SEFIN; esta versão não transforma a taxa municipal IBPT em ISSQN nem busca a alíquota legal do ISS na tabela IBPT.

## Referências primárias

- [FAQ oficial IBPT](https://deolhonoimposto.ibpt.org.br/Site/Faq): finalidade informativa, regimes tributários e desconto incondicional.
- [Termos da tabela IBPT](https://deolhonoimposto.ibpt.org.br/Site/termodeuso): fonte e uso dentro da vigência.
- [Informações de atualização IBPT](https://deolhonoimposto.ibpt.org.br/Site/PassoPasso).
- [Anexo I oficial da NFS-e Nacional](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/anexo_i-sefin_adn-dps_nfse-snnfse-v1-01-20260209.xlsx): grupos de tributos, totalização e regras de alíquota ISSQN.
- [Documentação técnica vigente](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual).

## Atualização e verificação

As migrações `071` a `075` não alteram esquema nem regravam documentos existentes. Instale o ZIP completo pelo atualizador nativo do Perfex e execute **Atualizar banco de dados** quando solicitado. A configuração anterior de tributos permanece até a escolha explícita do IBPT.

Os ensaios usam tabelas e identidades sintéticas para transmissão/PDF, com transporte controlado. A fonte pública foi consultada somente para validar o contrato e a vigência. Não foram emitidas ou canceladas notas, nem enviados e-mails reais durante esses testes.
