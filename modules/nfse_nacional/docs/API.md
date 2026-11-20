# Integração com a NFS-e Nacional

Pesquisa técnica realizada em 10/11/2026. Os endpoints e leiautes devem ser acompanhados nas publicações oficiais quando houver nova versão.

## Fontes principais

1. [Manual dos contribuintes — Emissor Público API v1.2](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf).
2. [APIs de produção restrita e produção](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao).
3. [Documentação atual de produção](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual).
4. [Documentação de homologação/testes](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita).
5. [NT 008 v1.02 — DANFSe](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-008-se-cgnfse-danfse-20260714-v1-02.pdf).
6. [Atualizações e implantações](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/atualizacoes-e-implantacoes/atualizacoes-e-implantacoes).
7. [Módulos e banco de dados no Perfex](https://help.perfexcrm.com/module-basics/).

O manual descreve o fluxo; os esquemas XSD e o OpenAPI detalham a estrutura. Uma validação XSD aprovada não substitui as regras de negócio aplicadas pela SEFIN nem a habilitação municipal.

## Autenticação

As chamadas da API usam HTTPS com autenticação mútua por certificado digital. A DPS também recebe assinatura XMLDSig envelopada sobre `infDPS`, com referência ao atributo `Id`. O módulo implementa o perfil de assinatura do padrão nacional (RSA-SHA1, SHA1 e canonicalização inclusiva) e verifica a assinatura antes de transmitir.

O A1 é aberto em memória, sua chave é conferida, o titular é comparado com o emitente e as datas de validade são verificadas. A confiança ICP-Brasil e a revogação continuam sujeitas à validação do ambiente nacional. TLS permanece com verificação do servidor habilitada; o cliente não segue redirecionamentos.

Não existe no contrato utilizado uma autenticação de emissão por usuário/senha do portal. O acesso interativo ao emissor web é um mecanismo diferente.

## Endereços implementados

| Serviço | Produção | Produção restrita |
| --- | --- | --- |
| SEFIN | `https://sefin.nfse.gov.br/SefinNacional` | `https://sefin.producaorestrita.nfse.gov.br/API/SefinNacional` |
| Parâmetros | `https://adn.nfse.gov.br/parametrizacao` | `https://adn.producaorestrita.nfse.gov.br/parametrizacao` |
| Distribuição ADN | `https://adn.nfse.gov.br/contribuintes` | `https://adn.producaorestrita.nfse.gov.br/contribuintes` |
| Consulta pública | `https://www.nfse.gov.br/ConsultaPublica/` | `https://www.producaorestrita.nfse.gov.br/ConsultaPublica/` |

Não são aceitas URLs arbitrárias pela interface. Isso evita encaminhar o A1 ou o conteúdo fiscal para um endereço informado na requisição.

Na versão 0.5.0, as bases acima foram novamente conferidas com a página oficial. O código usa `tpAmb=2` para produção restrita/homologação e `tpAmb=1` para produção, sem troca automática de host em caso de falha. A seleção deve corresponder ao ambiente do XML e do documento salvo.

O endereço local do estabelecimento está disponível nos parâmetros e na prévia. Para emissão pelo próprio prestador (`tpEmit=1`), nome e endereço da NFS-e autorizada são fornecidos pelo cadastro oficial RFB/CNC; a DPS não envia `prest/xNome` nem `prest/end`. Consulte as fontes e o alcance da conferência em [ESTABELECIMENTO_AMBIENTES.md](ESTABELECIMENTO_AMBIENTES.md).

## Operações

| Método e rota | Função | Implementação |
| --- | --- | --- |
| `POST /nfse` | Envia DPS e solicita emissão síncrona | Sim |
| `GET /dps/{id}` | Recupera chave pela DPS | Sim |
| `HEAD /dps/{id}` | Informa existência | Documentado; o módulo usa GET para recuperar a chave |
| `GET /nfse/{chaveAcesso}` | Recupera XML da NFS-e | Sim |
| `GET /{codigoMunicipio}/convenio` na base de parâmetros | Consulta convênio | Sim |
| Consultas municipais de alíquotas, benefícios e retenções | Parametrização complementar | Não implementadas nesta versão |
| `POST /nfse/{chaveAcesso}/eventos` | Registra evento assinado | Sim, eventos 101101, 101103, 203202 e 203206 |
| `GET /nfse/{chaveAcesso}/eventos` e variantes por tipo/sequência | Consulta eventos | Sim; confirmação de situação e recuperação de pedidos |
| `GET /DFe/{NSU}` na base de contribuintes ADN | Distribui notas e eventos disponíveis ao interessado | Sim; CPF/CNPJ explícito, cursor e lotes transacionais |
| `POST /nfse` com grupo `subst` | Substitui uma nota autorizada | Sim, para emissões locais vinculadas à fatura |
| Antiga API de geração DANFSe do ADN | PDF remoto | Suspensa; substituída por geração local no módulo |

O contrato detalhado das APIs foi consultado em cópias públicas do OpenAPI porque o Swagger autenticado não ficou acessível neste ambiente. Os endereços-base foram conferidos na página oficial. Os arquivos XSD incluídos têm proveniência registrada separadamente; não são descritos como download direto da distribuição oficial.

## Consulta do convênio e HTTP 404

A consulta municipal utiliza o **ADN — Parâmetros Municipais**, separado do serviço de emissão SEFIN. A página oficial de APIs, atualizada em 20/08/2026 e conferida em 10/11/2026, continua apontando os dois ambientes para `/parametrizacao/docs/index.html`. Por isso, a base `/parametrizacao` foi preservada. O manual de outubro de 2025 ainda apresenta `/parametros_municipais/{codigoMunicipio}/convenio`; essa diferença documental não autoriza substituir a rota atual nem enviar o certificado a endereços alternativos por tentativa.

O tratamento contempla a resposta municipal com `mensagem` e `parametrosConvenio`. A mensagem textual é extraída quando existe e o sucesso precisa conter os parâmetros do convênio. Um HTTP 200 sem esses dados continua inconclusivo. As mensagens dessa consulta identificam o ADN e não atribuem ao emissor SEFIN uma recusa fiscal.

**HTTP 404 não comprova que o município não seja conveniado.** Corpo vazio, página HTML, JSON genérico, erro de rota e resposta municipal com indicação de recurso indisponível permanecem consultas inconclusivas. O módulo informa o status HTTP e a mensagem recebida, quando ela segue o envelope reconhecido, sem inventar o motivo da indisponibilidade. A causa específica depende da resposta registrada para a empresa, município e ambiente consultados. Uma falha ao abrir o Swagger pela ferramenta de pesquisa também não demonstra indisponibilidade da API autenticada.

Na consulta de DPS, somente um erro estruturado com declaração expressa de ausência da DPS ou de seu identificador é convertido em `null`. Um 404 genérico ou de rota lança `ApiException` com `isUncertain() === true`, impedindo que a falha de consulta seja usada como confirmação de inexistência antes de um reenvio. A inexistência confirmada de uma DPS não é aplicada a consultas de convênio.

## Observação segura das chamadas HTTP

O quarto argumento opcional de `ApiClient` recebe `callable(array $event): void`. Os três argumentos anteriores continuam compatíveis. O callback é executado uma vez por tentativa de transporte, depois da validação da resposta. Exceções do callback são isoladas: indisponibilidade de log não repete uma transmissão nem transforma uma autorização em erro.

| Informação | Conteúdo do evento |
| --- | --- |
| Operação | `api.municipality`, `api.submit`, `api.find_dps` ou `api.fetch` |
| Destino | `service`, `environment`, `method` e URL oficial em `endpoint`, com identificadores substituídos por parâmetros |
| Resultado | `http_status` recebido e `operation_status`: `success`, `error`, `uncertain` ou `not_found` |
| Resposta | `response_type` distingue objeto JSON, lista/escalar JSON, HTML, XML, corpo vazio, JSON inválido, excesso de tamanho, corpo inválido e falha de transporte |
| Correlação técnica | `duration_ms`, quantidades de bytes e resumos SHA-256 da requisição e resposta |
| Erro | Códigos e mensagens limitadas; a camada persistente aplica adicionalmente sua proteção de dados |

O evento não contém certificado, chave privada, headers, credenciais, XML, Base64 ou corpos integrais. DPS, chave de acesso e código de município não aparecem na URL observada. Erros de transporte não expõem a mensagem original do cURL ou do proxy. Um HTTP 201 com identificação inconsistente, XML inválido ou ambiente divergente é registrado como resultado incerto, preservando o status HTTP efetivamente recebido.

Uma validação local que impeça a chamada antes do transporte não produz evento HTTP; o serviço da aplicação deve registrar essa etapa no histórico da operação. Não há tentativas automáticas adicionais de POST para produzir ou completar logs.

## Envio de DPS

O XML assinado é compactado com GZip e codificado em Base64:

```json
{
  "dpsXmlGZipB64": "BASE64_DO_GZIP_DO_XML_ASSINADO"
}
```

A resposta de sucesso contém ambiente, aplicação, data/hora de processamento, identificação da DPS, chave de acesso e XML da NFS-e em `nfseXmlGZipB64`. O módulo confere os campos e descompacta com limites de tamanho. HTTP 200/201 isoladamente não comprova autorização.

O identificador da DPS contém o prefixo `DPS`, código IBGE do município emissor (7), tipo de inscrição (1), inscrição federal (14; CPF completado à esquerda), série (5) e número (15). Total: 45 caracteres, considerando o prefixo.

O número é reservado de forma transacional. Uma trava por documento e a unicidade por fatura/ambiente/revisão impedem duas emissões simultâneas da mesma versão. A substituição mantém uma ligação única com a origem e uma trava fiscal por chave original. A sequência possui escopo por ambiente, emitente e série. O corpo assinado é persistido antes do POST.

## Rascunho e estados

| Estado local | Significado | Próxima ação |
| --- | --- | --- |
| Rascunho | Dados editáveis no Perfex | Salvar, validar ou emitir |
| Validada | Dados, XSD e assinatura conferidos localmente | Emitir |
| Em transmissão | Solicitação iniciada, DPS persistida | Aguardar/consultar |
| Autorizada | Resposta e XML correlacionados à DPS enviada | Baixar/enviar |
| Cancelada | Evento de cancelamento registrado, correlacionado à nota | Prévia carimbada, download e consulta; sem reenvio ou e-mail |
| Rejeitada | Rejeição fiscal estruturada | Corrigir e validar |
| Aguardando consulta | Resultado ambíguo, timeout ou conteúdo inconsistente | Consultar ou reenviar explicitamente a mesma DPS |

O estado fiscal e o estado do e-mail são independentes. Uma falha no SMTP não cancela nem repete a emissão fiscal.

Todos os estados permitem pré-visualização dos dados salvos. A prévia não reserva DPS, não valida com certificado e não envia à API. Notas autorizadas/canceladas usam o XML oficial recebido; demais estados são prévias sem validade fiscal. Consulte [PREVIA_DANFSE.md](PREVIA_DANFSE.md).

Desde 0.4.9, **Atualizar situação** realiza `GET /nfse/{chaveAcesso}/eventos` e persiste apenas cancelamento comprovado por evento registrado. 404 genérico e respostas sem XML não alteram a situação fiscal. Os identificadores de DPS/evento não são apresentados como protocolo de transmissão.

## Validação de negócio e cobertura

O município, o regime tributário, o serviço, a retenção, a competência e o tomador influenciam o aceite. O módulo permite informar esses dados e mostra os erros retornados. Ele não determina automaticamente o enquadramento fiscal do contribuinte.

As distribuições selecionadas são:

- **Produção:** `20260209`, DPS v1.01.
- **Produção restrita:** `20261020`, DPS v1.01.

No esquema de testes, `finNFSe` está em `infDPS`, a posição de `cNBS` mudou e `CST`/`cClassTrib` foram reposicionados. O builder respeita os arquivos de cada ambiente. A publicação da NT 009 não autoriza presumir que todas as suas alterações já estejam em produção.

A implementação básica de IBS/CBS informa finalidade regular, indicador de operação, consumidor final, classificação tributária e destinatário igual ao tomador. Grupos especiais e cálculos avançados não são inferidos pelo módulo. A ausência de um grupo exigido pela operação resulta em rejeição ou impedimento; não é preenchida com valores inventados.

## Correspondência e duplicidades

Recuperar uma chave pelo número da DPS não basta para vinculá-la a uma fatura. O conteúdo recebido também deve corresponder aos dados enviados. Isso protege contra uma série/numeração já utilizada por outro emissor.

Timeouts não geram uma nova DPS. A consulta utiliza o identificador persistido. A recuperação opcional por chave conhecida também exige a DPS original e correlação do conteúdo completo. O reenvio explícito mantém o corpo assinado original e faz nova consulta antes do POST. A aplicação não faz repetição automática de POST em resposta a erro HTTP.

Os fluxos de distribuição, eventos, recuperação, substituição e pacotes mensais, com seus limites, estão em [OPERACOES_070.md](OPERACOES_070.md). O módulo não adiciona um `nProt` ao XML da NFS-e; guarda o documento oficial e mostra os identificadores recebidos sem equiparar chave, DPS ou evento a protocolo de transmissão.

## Dados e fronteiras da integração

As tabelas fiscais usam exclusivamente o banco e o prefixo já escolhidos pelo runtime Perfex. O A1 pode ser obtido pelo serviço autenticado da empresa, respeitando seu contrato de acesso; a integração não consulta diretamente o cofre ou tabelas centrais de certificados. O Asaas é consultado apenas para identificar a cobrança vinculada à fatura; a emissão fiscal é feita pela API nacional.

CPF/CNPJ do pagador não é automaticamente a identificação do tomador. Cada cliente mantém suas preferências fiscais e seus tomadores; cada documento guarda um snapshot da operação. Autorizações respeitam permissões fiscais, acesso ao cliente e acesso à fatura.

Na versão 0.2.0, Configurações usam uma seção nativa e a fatura recebe um painel próprio por hooks administrativos. O endpoint `GET nfse_nacional/invoice_context/{id}` apenas devolve o painel autorizado; preparar e transmitir continuam sendo operações POST. A mudança de cliente da fatura após criar histórico fiscal bloqueia nova preparação.

O resolvedor A1, a autorização Multiempresa e o ponto de extensão para núcleo personalizado estão detalhados em [CERTIFICADOS.md](CERTIFICADOS.md).

## Catálogo e preparação de serviço (0.3.0)

O campo `cTribNac` recebe exatamente os seis dígitos do serviço selecionado no catálogo local. `cTribMun` e `cNBS` continuam campos independentes. Código e descrição padrão da prestação podem ser configurados por empresa, perfil de cliente e tomador, prevalecendo o mais específico. O texto livre `xDescServ` não é substituído automaticamente pela descrição oficial de classificação.

A grade usa o filtro `invoices_table_row_data` para abrir o contexto da fatura pelo endpoint de leitura `invoice_context/{id}`. A abertura não grava nem transmite. `prepare/{id}` e `emit_invoice/{id}` continuam operações POST com CSRF, autorização e contexto da empresa conferidos no servidor.

O catálogo diferencia ambientes e não habilita novos códigos pela passagem do tempo. Fontes e disponibilidade conferidas em 10/11/2026 estão em CATALOGO_SERVICOS.md.
