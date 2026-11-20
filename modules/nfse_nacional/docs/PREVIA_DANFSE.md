# Pré-visualização e situação fiscal — 0.4.9

## Uso no CRM

1. Prepare um rascunho pela fatura ou abra um documento em **NFSE → Documentos**.
2. Salve as alterações que deseja conferir.
3. Clique em **Pré-visualizar DANFSe**. O PDF dos dados salvos abre em outra aba; **Baixar DANFSe** obtém o mesmo documento como arquivo.
4. Após a autorização, a prévia passa a usar o XML recebido do ambiente nacional, com número, chave e processamento reais.
5. Use **Atualizar situação** para consultar os eventos já registrados. Um cancelamento confirmado atualiza o carimbo e preserva o XML original.

As ações também estão disponíveis na guia fiscal do cliente e no painel da fatura. A prévia funciona com rascunho incompleto e não depende de certificado A1. Dados não informados ficam vazios; valores fiscais calculados pelo autorizador não são simulados como definitivos.

## Significado dos carimbos

| Situação | PDF | Evidência |
|---|---|---|
| Rascunho sem tentativa | RASCUNHO / sem validade fiscal | Dados salvos localmente |
| Validada, sem envio | NÃO TRANSMITIDA / sem validade fiscal | Validação local não equivale a autorização |
| Dados corrigidos após tentativa | NÃO AUTORIZADA / versão atual não transmitida | Mantém o histórico da tentativa anterior |
| Rejeitada | REJEITADA / sem validade fiscal | Retorno fiscal de rejeição |
| Processando ou resultado incerto | AGUARDANDO CONFIRMAÇÃO / sem validade fiscal | A tentativa não comprova recebimento ou autorização |
| Autorizada | TRANSMITIDA E AUTORIZADA | XML retornado, correlacionado à DPS e ao ambiente |
| Cancelada | CANCELADA | Evento de cancelamento registrado, correlacionado e cifrado |

Produção restrita mantém indicação de teste e ausência de validade jurídica, inclusive nas notas autorizadas ou canceladas. A prévia local não recebe chave, número fiscal, protocolo ou QR de autenticidade artificiais. DPS já reservada pode aparecer com sua série e número existentes, sem consumir outra sequência.

## Identificação da transmissão

O painel separa início da tentativa local, processamento informado pelo autorizador, chave de acesso, número da nota, identificação da DPS e última consulta fiscal. O leiaute nacional consultado não possui o `nProt` típico da NF-e: a interface indica **Não informado pelo autorizador** quando não há protocolo. Identificador de evento `EVT` e `nDFSe` são apresentados como evento e registro, sem renomeá-los como protocolo.

A confirmação é da API do **Sistema Nacional NFS-e**. O módulo não declara uma transmissão adicional diretamente à Receita Federal. Timeout, falha de rede e HTTP 404 genérico não comprovam rejeição nem ausência da nota.

## Cancelamento e consulta

`GET /nfse/{chaveAcesso}/eventos` é a consulta descrita no manual oficial do contribuinte, seção 1.5.2. A ação não envia pedido de evento. O módulo reconhece cancelamentos registrados dos tipos `101101`, `105102`, `105104` (deferido) e `305101`; pedido de análise `101103` e indeferimento `105105` não cancelam a nota. O cancelamento de uma fatura no CRM não cancela automaticamente a NFS-e.

Se a rota coletiva retornar 404/405 inconclusivo, são consultadas as rotas por tipo e sequência `GET /nfse/{chaveAcesso}/eventos/{tipoEvento}/1`, documentadas no Swagger. A busca termina ao encontrar um cancelamento comprovado. Apenas ausência fiscal explícita nos quatro tipos confirma ausência; qualquer resposta genérica mantém a consulta inconclusiva. Erros de autenticação, limite, servidor, timeout ou conteúdo inválido com HTTP 200 não acionam esse procedimento. Nenhum POST fiscal é executado.

São conferidos namespace, estrutura, identificação, chave, ambiente, tipo, sequência e processamento do evento. O XML é recebido por conexão autenticada e armazenado cifrado, com revalidação antes do carimbo. Essa conferência estrutural não representa validação de cadeia ou revogação do certificado do assinante do evento.

O parser aceita os envelopes `eventoXmlGZipB64` e `eventos[].arquivoXml`, com limites de tamanho e descompactação. Resposta vazia ou genérica é inconclusiva. Uma ausência explícita de eventos deve identificar o ambiente e o objeto consultado. Uma consulta posterior vazia não reativa uma nota cujo cancelamento foi comprovado.

O PDF utiliza a última situação confirmada. Abrir ou baixar o arquivo não faz consulta remota; isso permite acesso sem certificado e evita alterar um documento durante a leitura. Falha na atualização preserva a evidência anterior. A atualização de uma nota já autorizada/cancelada não envia e-mail.

## Persistência e acesso

Migração `049`: duas colunas opcionais apenas na tabela de documentos do módulo — `fiscal_meta_json` e `cancellation_event_enc`. Registros antigos obtêm os dados de processamento do XML autorizado. Não altera tabelas nativas, sequências existentes ou parâmetros fiscais.

As rotas de prévia/download exigem visualização fiscal e acesso nativo ao documento/fatura/cliente, no contexto da empresa ativa. Apenas GET é aceito. PDFs têm `no-store` e não ficam publicados em pasta pública. A consulta fiscal usa POST interno com CSRF e faz apenas GET no serviço nacional. XML, certificado e senhas não são incluídos nos logs operacionais.

## Referências

- [Manual do contribuinte, API do Emissor Público Nacional, seção 1.5.2](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-emissor-publico-api-sistema-nacional-nfs-e-v1-2-out2025.pdf).
- [Lista oficial das APIs de produção e produção restrita](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao).
- Esquemas nacionais incluídos em `schemas/`; procedência e hashes em `schemas/provenance.json`.
- [Descrição de envelope observado pelo mantenedor do SDK OwnerPro](https://github.com/OwnerPro-Software/nfsen-php-sdk/blob/main/docs/superpowers/specs/2026-08-04-consulta-eventos-envelope-real-design.md): referência de compatibilidade, não substitui o manual oficial.
